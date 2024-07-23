# processor-orthogonal
  
This processor all CSV files (or sliced tables) in `/data/in/tables`, counts the maximum number of columns in the CSV file and fills all the rows so that each row contains the same number of columns. If some columns are missing from header, autogenerated columns are added. Use this processor when you receive the error 
`Load error: Line 1 - Extra column(s) found` when loading a table into Storage. Manifests files are updated with the new column list. Sliced files are supported.

All CSV files must:

- not have headers
- have a manifest file with columns, delimiter and enclosure properties

# Usage
The processor takes no options.

Example configuration:

```
{  
    "definition": {
        "component": "keboola.processor-orthogonal"
    }
}
```

For more information about processors, please refer to [the developers documentation](https://developers.keboola.com/extend/component/processors/). 

## Development
 
Clone this repository and init the workspace with following command:

```
git clone https://github.com/keboola/processor-orthogonal
cd processor-orthogonal
docker-compose build
docker-compose run --rm dev composer install --no-scripts
```

Run the test suite using this command:

```
docker-compose run --rm dev composer tests
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 
