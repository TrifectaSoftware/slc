<?php
namespace slc\reports;

class ReportLandlordTen extends Report {

    public $content;
    public $total;
    public $startDate;
    public $endDate;
    public $issuenames;
    public $emptyLandlord;
    public $overallCount;
    public $pdo;
    public $issueCount;

    public function __construct($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->execute();
    }

    public function execute() {

        $landlords = "SELECT * FROM slc_landlord";
        $db = new \PHPWS_DB();
        $landlords = $db->select(null, $landlords);


        $issues = 'SELECT *
                   FROM slc_problem
                        WHERE (slc_problem.type IN ("Landlord-Tenant", "Conditions")
                              OR (slc_problem.type = "Generic"
                              AND slc_problem.description IN ("Conditions", "Landlord-Tenant")))
                   ORDER BY slc_problem.tree, slc_problem.id';  // Covers generic landlord-tenant, too

        $db = new \PHPWS_DB();
        $issues = $db->select(null, $issues);
        $this->issuenames = array();
        foreach( $issues as $issue )
            $this->issuenames[] = $issue['description'];

        $db = \Database::newDB();
        $this->pdo = $db->getPDO();

        // Building issues count based on the number of conditions
        $this->issueCount = array();
        $countConditions = count($this->issuenames);
        for ($i = 0; $i < $countConditions; $i++)
        {
            $this->issueCount[] = 0;
        }

        $content = array();
        $total = array();
        foreach ($this->issuenames as $issue) {
            $content['landlord_issue_repeat'][] = array('ISSUE_NAME' => $issue);
        }

        foreach ($landlords as $landlord)
        {
            $row = $this->landlordRow($landlord);
            if ($row != null)
            {
                $content["landlord_tenant_repeat"][] = $row;
                $this->overallCount += $row["TOTAL"];
            }
        }

        foreach ($this->issuenames as $key => $issue)
        {
            $word = str_replace(" ", "_", $issue);
            $word = str_replace("/", "OR", $word);

            $total[strtoupper($word)."_TOTAL"] = $this->issueCount[$key];

        }

        $total["OVERALL_TOTAL"] = $this->overallCount;
        $this->total = $total;
        $this->content = $content;
    }

    private function landlordRow($landlord)
    {
        $row = array();
        $rowCount = 0;

        $query = 'SELECT slc_problem.description, count(*) as myCount
                  FROM slc_issue
                  JOIN slc_visit ON slc_visit.id = slc_issue.v_id
                  LEFT OUTER JOIN slc_landlord ON slc_issue.landlord_id = slc_landlord.id
                  JOIN slc_problem ON problem_id = slc_problem.id
                      WHERE (slc_problem.type IN ("Landlord-Tenant", "Conditions")
                            OR (slc_problem.type = "Generic"
                            AND slc_problem.description IN ("Conditions", "Landlord-Tenant")))
                            AND initial_date >= :startDate
                            AND initial_date <= :endDate
                            AND slc_landlord.id = :lId
                            GROUP BY slc_problem.description'; //change by landlord id

        $sth = $this->pdo->prepare($query);
        $sth->execute(array("startDate"=>$this->startDate, "endDate"=>$this->endDate, "lId"=>$landlord['id']));
        $result = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $row["NAME"] = $landlord['name'];

        foreach ($result as $r)
        {

            foreach ($this->issuenames as $key => $issue)
            {

                if ($r["description"] == $issue)
                {
                    $word = str_replace(" ", "_", $r["description"]);
                    $word = str_replace("/", "OR", $word);

                    $row[strtoupper($word)] = $r["myCount"];
                    $row['ISSUES'][strtoupper($word)] = $r["myCount"];
                    $rowCount += $r["myCount"];


                    $this->issueCount[$key] += $r["myCount"];
                    $this->emptyLandlord = false;
                }
                else
                {
                    $word = str_replace(" ", "_", $issue);
                    $word = str_replace("/", "OR", $word);

                    if (!isset($row[strtoupper($word)]) || $row[strtoupper($word)] < 1)
                    {
                        $row[strtoupper($word)] = 0;
                        $row['ISSUES'][strtoupper($word)] = 0;
                        $this->issueCount[$key] += 0;
                    }
                }
            }
        }

        $row["TOTAL"] = $rowCount;

        if ($this->emptyLandlord)
        {
            return null;
        }
        else
        {
            // Reset the flag otherwise it will remain false once a landlord has a value.
            $this->emptyLandlord = true;
            return $row;
        }
    }


    public function getHtmlView()
    {
        $content = $this->content;

        $tpl = new \PHPWS_Template('slc');

        $result = $tpl->setFile('LandlordTenant.tpl');

        foreach ($this->issuenames as $issueName)
        {
          $tpl->setCurrentBlock("landlord_issue_repeat");
          $tpl->setData(array("ISSUE_NAME"=>$issueName));
          $tpl->parseCurrentBlock();
        }

        foreach ($this->content['landlord_tenant_repeat'] as $landlord)
        {
            foreach ($landlord['ISSUES'] as $value)
            {
                 $tpl->setCurrentBlock("landlord_issues_repeat");
                 $tpl->setData(array("LANDLORD_ISSUE"=>$value));
                 $tpl->parseCurrentBlock();
            }
            $tpl->setCurrentBlock("landlord_tenant_repeat");
            $tpl->setData(array("LANDLORD_NAME"=>$landlord['NAME']));
            $tpl->setData(array("LANDLORD_TOTAL"=>$landlord['TOTAL']));
            $tpl->parseCurrentBlock();
        }

        foreach($this->total as $issueTotal)
        {
            $tpl->setCurrentBlock("totals_repeat");
            $tpl->setData(array("ISSUE_TOTAL"=>$issueTotal));
            $tpl->parseCurrentBlock();
        }

        return $tpl->get();
    }

    public function getCsvView()
    {
        $csvReport = new CsvReport();


        $issues = $this->content['landlord_issue_repeat'];
        $newIssues = array();

        // Grab each value from the arrays under the issues_repeat and merge into one array.
        foreach ($issues as $i)
        {
            $newIssues = array_merge($newIssues, array_values($i));
        }

        // Add a null to the front of the array for proper formatting in csv.
        array_unshift($newIssues, "");
        // Add Landlord Total to the column headings
        array_push($newIssues, "Landlord Total");
        $data = $csvReport->sputcsv($newIssues);


        // Grab each landlord row for parsing
        $landlords = $this->content['landlord_tenant_repeat'];
        foreach ($landlords as $l)
        {
            unset($l['ISSUES']);
            $data .= $csvReport->sputcsv($l);
        }


        $totals = $this->total;
        // Add a null to the front of the array for proper formatting in csv.
        array_unshift($totals, "Condition Total:");

        $data .= $csvReport->sputcsv($totals);

        return $data;
    }
}
