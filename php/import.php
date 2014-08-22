This file is where adjustments to the data are being made.  
I'm including it here temporarily to make it easier to work out a solution that does the same things in js.
looking at lines 168 to 239

<?php
$GLOBALS['Session']->requireAccountLevel('Developer');

$requiredColumns = [
    'FUNCTION_CLASS',
    'FUNCTION_CLASS_NAME',
    'FUNCTION_GROUP',
    'FUNCTION_GROUP_NAME',
    'FUNCTION',
    'FUNCTION_NAME',
    'ACTIVITY_CODE',
    'ACTIVITY_NAME',
    'OPERATING_CYEST_LUMPSUM_AMT',
    'GRANT_CYEST_LUMPSUM_AMT',
    'CAPITAL_CYEST_LUMPSUM_AMT',
    'OTHER_CYEST_LUMPSUM_AMT',
    'CYEST_LUMPSUM_TOT',
    'OPERATING_ACT_LUMPSUM_AMT',
    'GRANT_ACT_LUMPSUM_AMT',
    'CAPITAL_ACT_LUMPSUM_AMT',
    'OTHER_ACT_LUMPSUM_AMT',
    'ACT_LUMPSUM_TOT',
    'RUN_DATE'
];


if (!empty($_FILES['budget'])) {
    $reader = SpreadsheetReader::createFromFile($_FILES['budget']['tmp_name']);


    if (!$reader->hasColumns($requiredColumns)) {
        throw new Exception(
            'Spreadsheet is missing required column(s): '
            .join(',',array_diff($requiredColumns, $reader->getColumnNames()))
        );
    }

    try {
        DB::nonQuery('TRUNCATE TABLE `%s`', BudgetLine::$tableName);
        DB::nonQuery('TRUNCATE TABLE `%s`', NormalizedBudgetLine::$tableName);
    } catch (QueryException $e) {
        // ignore
    }

    $count = 0;
    while ($line = $reader->getNextRow()) {
        $BudgetLine = BudgetLine::create([
            'FunctionClass' => $line['FUNCTION_CLASS'],
            'FunctionClassName' => $line['FUNCTION_CLASS_NAME'],
            'FunctionGroup' => $line['FUNCTION_GROUP'],
            'FunctionGroupName' => $line['FUNCTION_GROUP_NAME'],
            'Function' => $line['FUNCTION'],
            'FunctionName' => $line['FUNCTION_NAME'],
            'ActivityCode' => $line['ACTIVITY_CODE'],
            'ActivityName' => $line['ACTIVITY_NAME'],
            'CurrentOperating' => $line['OPERATING_CYEST_LUMPSUM_AMT'],
            'CurrentGrant' => $line['GRANT_CYEST_LUMPSUM_AMT'],
            'CurrentCapital' => $line['CAPITAL_CYEST_LUMPSUM_AMT'],
            'CurrentOther' => $line['OTHER_CYEST_LUMPSUM_AMT'],
            'CurrentTotal' => $line['CYEST_LUMPSUM_TOT'],
            'ProposedOperating' => $line['OPERATING_ACT_LUMPSUM_AMT'],
            'ProposedGrant' => $line['GRANT_ACT_LUMPSUM_AMT'],
            'ProposedCapital' => $line['CAPITAL_ACT_LUMPSUM_AMT'],
            'ProposedOther' => $line['OTHER_ACT_LUMPSUM_AMT'],
            'ProposedTotal' => $line['ACT_LUMPSUM_TOT'],
            'RunDate' => strtotime($line['RUN_DATE'])
        ], true);
        $count++;
    }




    // clone data to normalized table
    try {
        DB::nonQuery(
            'INSERT INTO `%s` SELECT * FROM `%s`'
            ,[
                NormalizedBudgetLine::$tableName
                ,BudgetLine::$tableName
            ]
        );
    } catch(TableNotFoundException $e) {
        // auto-create table and try insert again
        DB::multiQuery(SQL::getCreateTable('NormalizedBudgetLine'));

        DB::nonQuery(
            'INSERT INTO `%s` SELECT * FROM `%s`'
            ,[
                NormalizedBudgetLine::$tableName
                ,BudgetLine::$tableName
            ]
        );
    }

    DB::nonQuery('UPDATE `%s` SET Class = "NormalizedBudgetLine"', NormalizedBudgetLine::$tableName);



    // list all the money columns
    $valueColumnsCurrent = ['CurrentOperating', 'CurrentGrant', 'CurrentCapital', 'CurrentOther', 'CurrentTotal'];
    $valueColumnsProposed = ['ProposedOperating', 'ProposedGrant', 'ProposedCapital', 'ProposedOther', 'ProposedTotal'];
    $valueColumns = array_merge($valueColumnsCurrent, $valueColumnsProposed);

    // this method generates a where SQL from an array of one or more line conditions
    $generateWhere = function($conditions) {
        if (!is_array($conditions[0])) {
            $conditions = array($conditions);
        }

        return '(' . implode(') OR (', array_map(function($conditions) {
            return implode(' AND ', NormalizedBudgetLine::mapConditions($conditions));
        }, $conditions)) . ')';
    };

    // this method removes lines matching one of the supplied conditions and returns their totals
    $extractLines = function($conditions) use ($valueColumns, $generateWhere) {
        $totals = array();
        foreach (NormalizedBudgetLine::getAllByWhere($generateWhere($conditions)) AS $Line) {
            foreach ($valueColumns AS $column) {
                $totals[$column] += $Line->$column;
            }
            $Line->destroy();
        }

        return $totals;
    };

    // this method proportionally distributes amounts among lines matching the supplied conditions
    $distributeAmounts = function($amounts, $conditions) use ($generateWhere) {
        $columns = array_keys($amounts); //columns contains keys for amounts (summed totals from extractLines)
        $lines = NormalizedBudgetLine::getAllByWhere($generateWhere($conditions)); //lines contains keys from Normalized Budget Line?

        // first pass through target lines -- sum existing amounts
        $totals = array(); 
        foreach ($lines AS $Line) {
            foreach ($columns AS $column) {
                $totals[$column] += $Line->$column; 
            }
        }
        //result: $totals contains 10 properties named after values in columnsCurrent/columnsProposed(i think)
        //each contains the sum of all data items that meet conditions


        // second pass -- distribute amounts proportionally
        $newTotals = array(); //stores new totals
        $defaultProportion = 1 / count($lines); //what to multiply each selected element by.  1 / #lines distributing to
        foreach ($lines AS $Line) { //for each line in distribution lines
            foreach ($totals AS $column => $total) {  //for each total(contains summed totals with a matching key)
                if ($total) { //if a total evaluates to true...  meaning it is not zero, i think...  and why would the proportion change b/c of that?
                    $proportion = $Line->$column / $total;// proportion = line[column] / total
                } else {//if total = 0?
                    $proportion = $defaultProportion;//proportion = default
                }

                $Line->$column += round($amounts[$column] * $proportion, 2); //line[column] += (amounts[column] *proportion) rounded to 2nd place.  This is actually editing the value in the tree
                $newTotals[$column] += $Line->$column; //newTotals[column] += line[column]
                //summing new totals in newTotals object.  why? what are we doing with it after this?
            }
            $Line->save();//and what's this doing?  saving modifications to the line is my guess...
        }
    };




    // extract gap closing cuts and undistributed budgetary adjustments
    $gapClosingAmounts = $extractLines([
        ['Function' => 'F49992', 'ActivityCode' => '114A']  // Budget Reductions - Instructional & Instructional Support
        ,['Function' => 'F49995', 'ActivityCode' => '114C'] // Budget Reductions - Operating Support
        ,['Function' => 'F49994', 'ActivityCode' => '114E'] // Budget Reductions - Administration
        ,['Function' => 'F49991', 'ActivityCode' => '114B'] // Budget Reductions - Pupil & Family Support
        ,['Function' => 'F41073', 'ActivityCode' => '5999'] // Undistributed Budgetary Adjustments - Other
        ,['Function' => 'F41073', 'ActivityCode' => '5221'] // Undistributed Budgetary Adjustments - Other
        ,['Function' => 'F41073', 'ActivityCode' => '5130'] // Undistributed Budgetary Adjustments - Other
        ,['Function' => 'F41073', 'ActivityCode' => '2817'] // Undistributed Budgetary Adjustments - Other
    ]);

    // split up gap closing / undistributed budgetary adjustments for District Operated Schools and Administrative budget lines by SDP-estimated ratios
    $gapClosingAmountsSchools = array();
    $gapClosingAmountsAdministrative = array();

    foreach ($gapClosingAmounts AS $column => $amount) {
        if (in_array($column, $valueColumnsCurrent)) {
            $gapClosingAmountsSchools[$column] = round($amount * 0.95183129854, 2); // 95.18% distribution of FY14 funds to schools
            $gapClosingAmountsAdministrative[$column] = $amount - $gapClosingAmountsSchools[$column];
        } elseif (in_array($column, $valueColumnsProposed)) {
            $gapClosingAmountsSchools[$column] = round($amount * 0.95441584049, 2); // 95.18% distribution of FY15 funds to schools
            $gapClosingAmountsAdministrative[$column] = $amount - $gapClosingAmountsSchools[$column];
        } else {
            throw new Exception('Unexpected column');
        }
    }

    // distribute split amounts
    $distributeAmounts($gapClosingAmountsSchools, [
        ['FunctionGroup' => 'F31330']   // District Operated Schools - Instructional
        ,['FunctionGroup' => 'F31350']  // District Operated Schools - Instructional Support
        ,['FunctionGroup' => 'F31620', 'Function != "F41038"']  // District Operated Schools - Operational Support
        ,['FunctionGroup' => 'F31360']  // District Operated Schools - Pupil - Family Support
    ]);

    $distributeAmounts($gapClosingAmountsAdministrative, [
        ['FunctionClass' => 'F21001'] // Administrative Support Operations
    ]);




    // misc distributions
    $distributeAmounts(
        $extractLines(['Function' => 'F49000', 'ActivityCode' => '5221']) // Food Service > Allocated Costs
        ,['FunctionGroup' => 'F31620', '(Function != "F41071" OR ActivityCode != "5221")', 'Function != "F41038"'] // Operating Support group, except Transportation -- Regular Services > Allocated Costs and Debt Service
    );

    $distributeAmounts(
        $extractLines(['Function' => 'F41071', 'ActivityCode' => '5221']) // Transportation -- Regular Services > Allocated Costs
        ,['Function' => 'F41071', 'ActivityCode != "5221"'] // Transportation -- Regular Services, except Allocated Costs
    );

    $distributeAmounts(
        $extractLines(['Function' => 'F41073', 'ActivityCode' => '2515']) // Undistributed Budgetary Adjustments - Other > ACCOUNTING SERVICES
        ,['Function' => 'F49027'] // Accounting & Audit Coordination
    );

    $distributeAmounts(
        $extractLines(['Function' => 'F41073', 'ActivityCode' => '2520']) // Undistributed Budgetary Adjustments - Other > CITY CONTROLLER
        ,['Function' => 'F41099'] // Financial Services Function
    );

    $distributeAmounts(
        $extractLines(['Function' => 'F41073', 'ActivityCode' => '2512']) // Undistributed Budgetary Adjustments - Other > OFFICE OF MANAGEMENT & BUDGET
        ,['Function' => 'F49026'] // Management & Budget Office function
    );

    $distributeAmounts(
        $extractLines(['Function' => 'F41073', 'ActivityCode' => '2519']) // Undistributed Budgetary Adjustments - Other > OFFICE OF MANAGEMENT & BUDGET
        ,['Function' => 'F49026'] // Management & Budget Office function
    );



    // check accuracy of normalization
    $originalSums = DB::oneRecord(
        'SELECT '
        . implode(', ', array_map(function($column) { return "SUM($column) AS $column"; }, $valueColumns))
        . ' FROM `%s`'
        ,BudgetLine::$tableName
    );

    $normalizedSums = DB::oneRecord(
        'SELECT '
        . implode(', ', array_map(function($column) { return "SUM($column) AS $column"; }, $valueColumns))
        . ' FROM `%s`'
        ,NormalizedBudgetLine::$tableName
    );

    $sumDifferences = array();
    foreach ($normalizedSums AS $column => $normalizedSum) {
        $sumDifferences[$column] = $normalizedSum - $originalSums[$column];
    }

    Debug::dumpVar($sumDifferences, false, 'Normalized table net differences');


    die("Imported $count rows");
}

?>
<html>
    <head>
        <title>Budget Importer</title>
    </head>
    <body>
        <form enctype='multipart/form-data' method='POST'>
            <input type='file' name='budget'>
            <input type='submit' value='Upload'> (Existing table will be erased)
        </form>
    </body>
</html>
Status API Training Shop Blog About © 2014 GitHub, Inc. Terms Privacy Security Contact 