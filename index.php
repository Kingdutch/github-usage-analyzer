<!DOCTYPE html>
<html>
<head>
    <title>GitHub Actions Usage Analyzer</title>
    <style>
        html, body {
            margin: 0;
        }

        form, .error {
            margin: 1rem;
            padding: 1rem;
        }

        .range {
            font-family: sans-serif;
            font-size: 1.75rem;
            margin: 1rem 1rem;
        }
        .range .date {
            white-space: nowrap;
        }
        .toggles {
            margin: 0 1rem;
        }

        form {
            border: 2px solid black;
        }
        .error {
            border: 2px solid red;
        }

        .results {
            margin: 1rem;
            display: flex;
            gap: 1rem;
            flex-direction: column;
        }
        .results .table {
            flex: 0 0 auto;
            border: 1px solid black;
            padding: 1rem;
        }
        .results .table table {
            border-spacing: 1rem .2rem;
        }
        .results .table table th {
            cursor: pointer;
        }
        .results .column[data-title='Cost ($)'],
        .results .column[data-title='Minutes'] {
            text-align: right;
        }
        .results .column[data-title='Cost ($)']:before {
            content: '$';
            float: left;
            margin-right: .5rem;
        }

        .hidden {
            display: none;
        }
        
        @media (min-width: 800px) {
            form, .error {
                display: inline-block;
                min-width: 800px;
            }

            .results {
                flex-direction: row;
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>

<div>
<form enctype="multipart/form-data" method="post">
    <p>
        <label for="usage_report">GitHub Usage Report</label>
    </p>
    <p>
        <input type="file" name="usage_report" id="usage_report" />
    </p>
    <p><input type="submit" value="Analyze"></p>
</form>
</div>

<?php

/**
 * Creates a reducer function to sum all time and costs by a specific column.
 *
 * @param string $column
 *   The column to sum by (e.g. date to sum all costs per day).
 *
 * @return \Closure
 *   A closure to pass as second argument to `array_reduce` (initial carry must
 *   be an empty array).
 */
function reduce_by_column(string $column, string $label) {
    return function (array $carry, array $row) use ($column, $label) {
        $field = $row[$column];
        if (!isset($carry[$field])) {
            $carry[$field] = [
                $label => $field,
                'Minutes' => 0,
                'Cost ($)' => 0,
                'Repositories' => [],
            ];
        }

        if ($row['unit_type'] !== 'minute') {
            throw new \RuntimeException("Unexpected unit type '" . $row['unit_type'] . "'");
        }

        $carry[$field]['Minutes'] += $row['amount'];
        $carry[$field]['Cost ($)'] += $row['amount'] * $row['unit_price_dollar'];
        $carry[$field]['Repositories'][] = $row['repository'];

        return $carry;
    };
}

/**
 * Handle the file upload.
 *
 * Performed in a function so we can buffer output and perform an early return.
 *
 * @return NULL|FALSE|array
 *   NULL in case no file was uploaded. False in case of an invalid uploaded
 *   file or an array of the analysis results.
 *   Whether it was successfully handled or NULL if no file was uploaded.
 */
function handle_file_upload() : NULL|FALSE|array {
    if (!isset($_FILES['usage_report'])) {
        return NULL;
    }

    if (!isset($_FILES['usage_report']['type'])) {
        echo "There was an error uploading the files.";
        return FALSE;
    }

    if ($_FILES['usage_report']['type'] !== "text/csv") {
        echo "You must upload a file in the CSV format.";
        return FALSE;
    }

    $file = fopen($_FILES['usage_report']['tmp_name'], 'rb');
    if ($file === FALSE) {
        echo "Could not open uploaded file.";
        return FALSE;
    }

    // Map the fields in the CSV to more easy to type strings.
    $field_mapping = [
        'Date' => 'date',
        'Product' => 'product',
        'SKU' => 'product_type',
        'Quantity' => 'amount',
        'Unit Type' => 'unit_type',
        'Price Per Unit ($)' => 'unit_price_dollar',
        'Multiplier' => 'unit_price_multiplier',
        'Owner' => 'owner',
        'Repository Slug' => 'repository',
        'Username' => 'username',
        'Actions Workflow' => 'workflow'
    ];

    $header = fgetcsv($file);
    $header_lookup = array_flip($header);

    // Validate that all our expected fields are there.
    $missing_header_fields = array_diff(array_keys($field_mapping), $header);
    if (count($missing_header_fields) !== 0) {
        echo "Malformed CSV file, missing header fields: " . implode(", ", $missing_header_fields);
        return FALSE;
    }

    $rows = [];
    // Read the data from the file line by line and convert into an array of
    // rows which are formatted by our machine name fields. fgetscsv will read
    // lines with a numeric index. We must use the first line which is our
    // header to map our actual fields to locations within the line. We must do
    // this through a header => index lookup to make our code resistant to
    // changes in field order of the CSV files.
    while (($data = fgetcsv($file)) !== FALSE) {
        // Filter out anything that's not a GitHub workflow action.
        if ($data[$header_lookup["Product"]] !== "Actions") {
            continue;
        }
        $row = [];
        foreach ($field_mapping as $header => $system_name) {
            $row[$system_name] = $data[$header_lookup[$header]];
            if ($system_name === 'amount') {
                $row[$system_name] = (int) $row[$system_name];
            }
            elseif ($system_name === 'unit_price_dollar') {
                $row[$system_name] = (float) $row[$system_name];
            }
            elseif ($system_name === 'unit_price_multiplier') {
                $row[$system_name] = (float) $row[$system_name];
            }
        }
        $rows[] = $row;
    }

    // The number of decimal places in price per unit determines the amount of
    // decimal places in all of our multiplied output, so we need to find that.
    $desired_decimal_places = array_reduce(
        $rows,
        fn (int $max, array $row) => max($max, (int) strpos(strrev((string) $row['unit_price_dollar']), ".")),
        0
    );

    $tables = [
        'Per Day' => array_reduce(
            $rows,
            reduce_by_column("date", "Day"),
            []
        ),
        'Per User' => array_reduce(
            $rows,
            reduce_by_column('username', "Username"),
            [],
        ),
        'Per Workflow' => array_reduce(
          $rows,
          reduce_by_column('workflow', "Workflow"),
          [],
        ),
        'Per Repository' => array_reduce(
            $rows,
            reduce_by_column('repository', "Repository"),
            [],
        )
    ];

    foreach ($tables as $name => &$table) {
        foreach ($table as &$row) {
            if ($name === "Per Repository") {
                unset($row['Repositories']);
            }
            else {
              $row['Repositories'] = implode(", ", array_unique($row['Repositories']));
            }
            $current_decimal_places = (int) strpos(strrev((string) $row['Cost ($)']), ".");
            $row['Cost ($)'] .= str_pad("", $desired_decimal_places - $current_decimal_places, "0");
        }
    }

    return $tables;
}

ob_start();
$result = handle_file_upload();
$output = ob_get_clean();
assert($output !== FALSE, "Output buffering as not started. Forgot to call ob_start.");

if (is_array($result)) {
    $days = array_keys($result['Per Day']);
    $to = date("l jS F Y", strtotime(end($days)));
    $from = date("l jS F Y", strtotime(reset($days)));

    echo "<h1 class='range'><span class='date'>$from</span> to <span class='date'>$to</span></h1>";
    ?>
    <div class="toggles">
        <?php
        // A "simple" CSS only way to hide/show certain overviews.
        foreach (array_keys($result) as $type) {
            echo "<label><input class='table-toggle' type='checkbox' value='$type' checked />$type</label>";
        }
        ?>
    </div>

    <div class="results">
    <?php
    foreach ($result as $type => $table) {
        $header = array_keys(current($table));
        ?>
        <div class="table" data-title="<?php echo $type; ?>">
        <table>
            <thead>
            <tr>
                <th colspan="<?php echo count($header); ?>"><?php echo $type; ?></th>
            </tr>
            <tr>
                <?php
                    foreach ($header as $col) {
                        ?>
                        <th><?php echo $col; ?></th>
                        <?php
                    }
                ?>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach ($table as $row) {
              echo "<tr>";
              foreach ($row as $label => $column) {
                  echo "<td class='column' data-title='$label'>$column</td>";
              }
              echo "</tr>";
            }
            ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    ?>
    </div>
    <?php
}
elseif ($result === FALSE) {
    ?>
    <div class="error">
        <?php echo $output; ?>
    </div>
    <?php
}

?>
<script type="text/javascript">
    /**
     * @block
     * Provides functionality to toggle different tables on- and off.
     *
     * Allows a user to hide a noisy table they're not interested in right now.
     */
    function toggleTable () {
        console.log(this);
        for (const table of document.querySelectorAll(`.results .table[data-title='${this.value}']`)) {
          if (this.checked) {
            table.classList.remove("hidden");
          }
          else {
            table.classList.add("hidden");
          }
        }
    }
    for (const toggle of document.getElementsByClassName('table-toggle')) {
      toggleTable.apply(toggle);
      toggle.addEventListener('change', toggleTable);
    }

    /**
     * @block
     * Adds on-click sorting to the table headers.
     */
    // Get the text value of a cell.
    const getCellValue = (tr, idx) => tr.children[idx].innerText || tr.children[idx].textContent;

    // Compare one table cell to another and return the neede value for a sort
    // function.
    const comparer = (idx, asc) => (a, b) => ((v1, v2) =>
        v1 !== '' && v2 !== '' && !isNaN(v1) && !isNaN(v2) ? v1 - v2 : v1.toString().localeCompare(v2)
    )(getCellValue(asc ? a : b, idx), getCellValue(asc ? b : a, idx));

    // Add an event listener to all the header cells and when clicked, sort all
    // the rows except for the header based on the row's value.
    document.querySelectorAll('th').forEach(th => th.addEventListener('click', (() => {
      const table = th.closest('table').querySelector('tbody');
      Array.from(table.querySelectorAll('tr'))
        .sort(comparer(Array.from(th.parentNode.children).indexOf(th), this.asc = !this.asc))
        .forEach(tr => table.appendChild(tr) );
    })));
</script>
</body>
</html>

