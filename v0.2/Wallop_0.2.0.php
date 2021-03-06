<?php
/* This file is part of Wallop.
 * Wallop is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Wallop is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with Wallop.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Developed by: Ryan Badour, Derek Hamilton, and Quinn Strahl
 */


 
// Enter custom database connection details here
global $_WALLOP;
$_WALLOP['mysql'] = array();
$_WALLOP['mysql']['host']     = 'localhost';
$_WALLOP['mysql']['database'] = 'YourDatabase';
$_WALLOP['mysql']['user']     = 'databaseUser';
$_WALLOP['mysql']['password'] = 'databaseUserPassword';

require_once('WallopDatabase.php');
global $database;

$database = new WallopDatabase();
$database->connect();



/* Wallop v0.2.0
 - A major change to the way configuration data is stored for objects. Moved things like relation column
   names and relation configuration to a static array for classes. This makes objects a lot more slimmed down
   than before.
 - Added the ability to initialize an object based on a unique key of columns. This means you can do things
   like logging in users elegantly using Wallop.
     - These new changes have also brought about changes to the constructor's arguments, $id has been moved
	   to the end of the parameters and replaced by $uniqueValues and $uniqueColumns.
	 - You can pass single values to into the constructor instead of the array of values and columns
	 - You can pass one $uniqueValue but no $uniqueColumn and it will initialize using the `id` (primary key)
 - Added null column validation to set()
 - Added the ability to apply column templates to Wallop objects, this allows you to get objects with only
   the columns you actually need at for the current script. You can use the new feature by implementing
   new classes that inherit from your base object (the one that inherits from Wallop) and passing the
   requested and rejected column variables along into Wallop's constructor
 - Added the getTemplateColumns() function
 - Modified the way that columns are used in commiting the object. Now you can commit an new object without
   setting some fields and it will properly use the default value you setup (instead of an empty string)
 - Added the isViolatingUnique() function that checks the database based on the records in this object and
   return whether or not committing this object now would cause unique violations in the database
 - Fixed some minor spelling mistakes
 - Fixed some minor validation problems in the constructor
 */
abstract class Wallop
{
	// Class members
	
	/* $classInfo:
	 * ['Table Name'] =>
	 *     array( ['defaultColumnName'] => 'Column Name' (optional),
	 *            ['aliasMap']          => array ( ['Alias Name'] => 'Relation Table Name' ),
	 *            ['columns']           => array( [] => 'Column Name' ),
	 *            ['notNullColumns']    => array( ['Column Name'] => true ),
	 *            ['uniqueKeys']        => array( ['Key Name'] => array(['Column Name'] => true) ),
	 *            ['aggregatesInfo']    => *See below,
	 *            ['compositesInfo']    => *See below
	 *          )
	 * 'aggregatesInfo' and 'compositesInfo':
	 * ['Relation Table Name'] => 
	 *     array( ['className']     => 'Relation Class Name',
	 *            ['relationColumnName']    => 'Relation Table Column Name',
	 *            ['otherRelationColumnName'] => 'Other Relation Column Name' (optional)
	 *          )
	 */
	private static $classInfo = array();
	
	private static $staticErrors = array(); // [] => 'Static error message'
	
	
    private $id;                    // 'Primary Key Value'
    private $objectInfo = array(); // ['tableName'] => 'Table Name',
								     // ['valueChanged'] => (bool)$hasARecordChanged,
								     // ['toBeRemoved'] => (bool)$isMarkedForRemoval
    private $aggregates = array(); // *See below
    private $composites = array(); // *See below
    private $record     = array(); // ['Column Name'] => $columnValue
    private $errors     = array(); // [] => 'Error message'
	
	/* $aggregates and $composites: (they have the same structure)
	 *		
	 * ['Relation Table Name'] =>
	 * 		array( ['objects']                 => array( [$relative->id] => $relativeObj ),
	 *             ['modifiedObjects']         => array( [$relative->id] => true ),
	 *             ['newObjects']              => array( [$relative->id] => true ),
	 *             ['removedObjects']          => array( [$relative->id] => true ),
	 *             ['removedStoredObjects']    => array( [$relative->id] => true ),
	 *             ['uninitializedNewObjects'] => array( [$relative->id] => $relativeObj ),
	 *             ['originalObjects']         => array( [$relative->id] => $originalRelativeObj ),
	 *             ['storedGets']              =>
	 *                  array( [] => array( ['start'] => (int)$start, ['amount'] => (int)$amount
     *					                    ['sortBy'] => 'Sort by Column,
	 *                                      ['sort'] => 'ASC'/'DESC',
	 *                                      ['conditions'] => 'A Where Clause',
	 *                                      ['mapOfIds'] => array( [$relative->id] => true )
	 *                                    )
	 *                       )
	 *           )
	 */
	
	// Functions related to intialization and getting object info
	// ============================================================================================
   
    /* 
     * $objectInfo: Array of data that configures this object
	 *   -> 'tableName' (string): the table name for this object in the database
	 *   -> 'defaultColumnName' (string): the default column name on relation tables for this object
	 *   -> 'requestedColumns' (array of string): an array of column names that will be
	 *                                            retrieved from the database for this
	 *                                            object. No other columns will be retrieved
	 *   -> 'rejectedColumns'  (array of string): an array of column names that will not be
	 *                                            retrieved from the database, this array will
	 *                                            override those in requestedColumns
     * $relations: Array of associative arrays detailing the relation between this class and other objects
     *   -> 'className' (string): the class name of the other object
	 *   -> 'relationColumnName' (string): the name of the column on the relation table for this relation
	 *   -> 'otherRelationColumnName' (string): the name of the other column in a self relation (optional)
     *   -> 'relationTableName' (string): the name of the relation table
	 *   -> 'functionalAlias' (string): the unique name given to this relation used for calling functions
	 *                                  that handle relations (like getRelatives, setRelatives, etc)
     *   -> 'dependency' ('composite', 'aggregate'): whether the other object is necessary for this object to
	 *                                               exist
	 * $uniqueValues: Array of values to initialize the object 
	 * $uniqueColumns: Array of columns to compare the $uniqueValue with, used to initialize the object.
	 *                 If no columns are specified then the first value in the array will be used to
	 *                 initialize the object on the id column (if there is one)
     */
    public function Wallop(array $objectInfo, array $relations = null, 
	                         $uniqueValues = null, $uniqueColumns = null)
    {
		// Validate parameters	
		if ( !isset($objectInfo['tableName']) || !is_string($objectInfo['tableName']) || 
		     $objectInfo['tableName'] == '')
		{
			$this->errors[] = 'Constructor: $objectInfo[\'tableName\'] must be a non-empty string!';
			return;
		}
		$tableName = $objectInfo['tableName'];
		
		// Go through the requested and rejected arrays for columns
		// unset and error on elements that aren't string
		if ( isset($objectInfo['requestedColumns']) && !empty($objectInfo['requestedColumns']) )
		{
			// if it is not an array
			// then convert the single item to an array of one
			if ( !is_array($objectInfo['requestedColumns']) )
				$objectInfo['requestedColumns'] = array($objectInfo['requestedColumns']);
			
			$requestedColumns = array();
			foreach ($objectInfo['requestedColumns'] as $reqCol)
			{
				if ( !is_string($reqCol) || $reqCol == '' )
				{
					$error  = 'Constructor: In the $objectInfo[\'requestedColumns\'] array element with key \''.$key.'\' ';
					$error .= ' is not a string or is empty!';
					$this->errors[] = $error;
					return;
				}
				
				$requestedColumns[$reqCol] = true;
			}
			
			$this->objectInfo['requestedColumns'] = $requestedColumns;
		}
		
		if ( isset($objectInfo['rejectedColumns']) && !empty($objectInfo['rejectedColumns']) )
		{
			// if it is not an array
			// then convert the single item to an array of one
			if ( !is_array($objectInfo['rejectedColumns']) )
				$objectInfo['rejectedColumns'] = array($objectInfo['rejectedColumns']);
				
			$rejectedColumns = array();
			foreach ($objectInfo['rejectedColumns'] as $key => $rejCol)
			{
				if ( !is_string($rejCol) || $rejCol == '' )
				{
					unset($objectInfo[$key]);
					$error  = 'In the $objectInfo[\'rejectedColumns\'] array element with key \''.$key.'\' ';
					$error .= ' is not a string or is empty and will be ignored.';
					$this->errors[] = $error;
				}
				
				$rejectedColumns[$rejCol] = true;
			}
			
			$this->objectInfo['rejectedColumns'] = $rejectedColumns;
		}
		
		// If the configuration is already setup for this object
		// Then setup the basic info for this object and stop the rest of the validation
		if ( isset(self::$classInfo[$tableName]) )
		{
			$this->objectInfo['tableName'] = $tableName;
			
			$this->aggregates = array();
			foreach (self::$classInfo[$tableName]['aggregatesInfo'] as $relTableName => $unused)
				$this->aggregates[$relTableName] = array();
				
			$this->composites = array();
			foreach (self::$classInfo[$tableName]['compositesInfo'] as $relTableName => $unused)
				$this->composites[$relTableName] = array();
			
			if ( isset($uniqueValues) )
				$this->initialize($uniqueValues, $uniqueColumns);
			
			return;
		}
		
		if ( isset($objectInfo['defaultColumnName']) )
		{
			if ( !is_string($objectInfo['defaultColumnName']) || $objectInfo['defaultColumnName'] == '')
			{
				$error  = 'Constructor: if $objectInfo[\'defaultColumnName\'] is set it must be a ';
				$error .= 'non-empty string!';
				$this->errors[] = $error;
				return;
			}
			$defaultColumnName = $objectInfo['defaultColumnName'];
		}
	
        // If this this Wallop is related to other Wallops
		$this->aggregates = array();
		$this->composites = array();
        if ( isset($relations) || !empty($relations) )
        {
            foreach ( $relations as &$relation )
            {
                if ( !is_array($relation) )
                {
                    $this->errors[] = 'Constructor: arg $relations must be an array of arrays.';
                    return;
                }
                
                if ( !isset($relation['className']) || $relation['className'] == '' ||
				     !isset($relation['relationTableName']) || $relation['relationTableName'] == '')
                {
                    $error = 'Constructor: arg $relations must have arrays that all';
                    $error .= ' contain \'className\' and \'relationTableName\'.';
                    $this->errors[] = $error;
                    return;
                }
				if ( !is_string($relation['className']) || !is_string($relation['relationTableName']) )
				{
					$error  = 'Constructor: Arg $relations must only have arrays where \'className\' ';
					$error .= 'and \'relationTableName\' are strings!';
					$this->errors[] = $error;
					return;
				}
				
				if ( !isset($defaultColumnName) && !isset($relation['relationColumnName']) )
				{
					$error  = 'Constructor: If you do not specify a default column name for this object ';
					$error .= 'then you must specify a \'relationColumnName\' name for all relations! ';
					$error .= 'You did not do this for relation `'.$relation['className'].'`.';
					$this->errors[] = $error;
					return;
				}
				
				if ( isset($relation['relationColumnName']) && 
				     (!is_string($relation['relationColumnName']) || $relation['relationColumnName'] == '') )
				{
					$error = 'Constructor: relation \''.$relation['relationTableName'].'\' specified a ';
					$error = 'relation column name that is not a string or is empty!';
					$this->errors[] = $error;
					return;
				}
				
				// Check if this className refers to a Wallop inheriting object
				if ( !class_exists($relation['className']) || 
				     !is_subclass_of($relation['className'], 'Wallop') )
				{
					$error  = 'Constructor: Relation with class \''.$relation['className'].'\' does not ';
					$error .= 'inherit Wallop!';
					$this->errors[] = $error;
					return;
				}
				
				if ( isset($relation['otherRelationColumnName']) ) 
				{
					if ( isset($relation['relationColumnName']) )
					    if ($relation['otherRelationColumnName'] == $relation['relationColumnName'] )
						{
							$error  = 'Constructor: Relation \''.$relation['relationTableName'].'\' ';
							$error .= 'specified a \'otherRelationColumnName\' that is equal to the first ';
							$error .= 'column. The two columns must be different.';
							$this->errors[] = $error;
							return;
						}
					else
						if ($relation['otherRelationColumnName'] == $defaultColumnName)
						{
							$error  = 'Constructor: Relation \''.$relation['relationTableName'].'\' ';
							$error .= 'specified a \'otherRelationColumnName\' that is equal to the ';
							$error .= 'default column. Since no \'relationColumnName\' was specified for ';
							$error .= ' this relation the two columns must be different.';
							$this->errors[] = $error;
							return;
						}
				}
				
				if ( isset($relation['functionalAlias']) && 
				     (!is_string($relation['functionalAlias']) || $relation['functionalAlias'] == '') )
				{
					$error = 'Constructor: relation \''.$relation['relationTableName'].'\' specified a ';
					$error = 'functional alias that is not a string or is empty!';
					$this->errors[] = $error;
					return;
				}
				
                if ( !isset($relation['dependency']) || $relation['dependency'] == '' ||
				     !is_string($relation['dependency']) )
                     $relation['dependency'] = 'aggregate';

                if ( $relation['dependency'] != 'aggregate' &&
                     $relation['dependency'] != 'composite' )
                {
                    $error = 'Constructor: arg $relation must have \'dependency\' ';
                    $error .= 'set to \'aggregate\' or \'composite\'!';
                    $this->errors[] = $error;
                    return;
                }
            }
			unset($relation);

			// Setup our static array for storing information about this class
			self::$classInfo[$tableName] = array();
			$classInfo = &self::$classInfo[$tableName];
			
			$classInfo['aggregatesInfo'] = array();
			$classInfo['compositesInfo'] = array();
            // Only build it after all relations are checked
            foreach ($relations as &$relation)
            {
                if ($relation['dependency'] == 'aggregate')
				{
					$targetArray = &$this->aggregates;
					$staticTargetArray = &$classInfo['aggregatesInfo'];
				}
                else
				{
					$targetArray = &$this->composites;
					$staticTargetArray = &$classInfo['compositesInfo'];
				}
				
				// Check for duplicate relationTableNames
				if ( isset($targetArray[$relation['relationTableName']]) )
				{
					$error  = 'Constructor: A duplicate entry of relation \''.$relation['relationTableName'];
					$error .= '\' has been found. Fix the configuration you provided and change the ';
					$error .= 'duplicate!';
					$this->errors[] = $error;
					return;
				}
				
				$targetArray[$relation['relationTableName']] = array();
				$targetArray = &$targetArray[$relation['relationTableName']];
				
				$staticTargetArray[$relation['relationTableName']]['className'] = $relation['className'];
				$staticTargetArray = &$staticTargetArray[$relation['relationTableName']];
						
				if ( isset($relation['relationColumnName']) )
					$staticTargetArray['relationColumnName'] = $relation['relationColumnName'];
						
				if ( isset($relation['otherRelationColumnName']) )
					$staticTargetArray['otherRelationColumnName'] = $relation['otherRelationColumnName'];
					
				if ( isset($relation['functionalAlias']) )
				{
					if ( isset($classInfo['aliasMap'][$relation['functionalAlias']]) )
					{
						$error  = 'Constructor: A duplicate alias has been found with on relation ';
						$error .= '\''.$relation['relationTableName'].'\', all aliases must be unique!';
						$this->errors[] = $error;
						return;
					}
					$classInfo['aliasMap'][$relation['functionalAlias']] = $relation['relationTableName'];
				}
            }
			unset($relation);
        }
		else
		{
			// If $relations isn't set than the $classInfo array won't be set for this class
			self::$classInfo[$tableName] = array();
			$classInfo = &self::$classInfo[$tableName];
		}

        // At this point we assume all data is set properly
        $this->objectInfo['tableName'] = $tableName;
		if ( isset($defaultColumnName) )
			$classInfo['defaultColumnName'] = $defaultColumnName;
        
        if ( isset($uniqueValues) )
			$this->initialize($uniqueValues, $uniqueColumns);
    }

    /* Setups up the list of columns for the Wallop object.
     * This function is only called when the object needs
     * the thing this function sets up.
     */
    private function constructTemplate()
    {
		$classInfoArray =& self::$classInfo[$this->objectInfo['tableName']];
	
        // Don't do anything if the object is already setup
        if ( !isset($classInfoArray['columns']) || count($classInfoArray['columns']) == 0 )
        {
			global $database;
			$result = $database->execQuery('SHOW COLUMNS FROM `'. $this->objectInfo['tableName'].'`');
			// If the table doesn't exist
			if ( !$result )
			{  
				$error  = 'constructTemplate(): Table \''.$this->objectInfo['tableName'].'\' doesn\'t '; 
				$error .= 'exist! Check the tableName you set in the constructor.';
				$this->errors[] = $error;
				return false;
			}

			$rows = $database->getAllRows();
			if ( !$rows )
			{
				$error  = 'constructTemplate(): Cannot get columns for table ';
				$error .= '`'.$this->objectInfo['tableName'].'` !';
				$this->errors[] = $error;
				return false;
			}

			$classInfoArray['columns'] = array();
			
			$i = 0;
			$size = count($rows);
			while($i != $size)
			{
				$row = $rows[$i];
				
				if ($row['Field'] == 'id')
				{
					++$i;
					continue;
				}

				// Setup the $columns class member
				$classInfoArray['columns'][] = $row['Field'];
				
				if ($row['Null'] = 'NO')
					$classInfoArray['notNullColumns'][$row['Field']] = true;
				
				++$i;
			}
			
			
			// Find the unique keys of this table (including the primary key)
			$database->execQuery('SHOW KEYS FROM `'. $this->objectInfo['tableName'].'`');
			$rows = $database->getAllRows();
			if ( !$rows )
			{
				$error  = 'constructTemplate(): Cannot get keys for table ';
				$error .= '`'.$this->objectInfo['tableName'].'` so that the unique keys can be found !';
				$this->errors[] = $error;
				return false;
			}
			
			$classInfoArray['uniqueKeys'] = array();
			$i = 0;
			$numRows = count($rows);
			while ($i != $numRows)
			{
				$row = $rows[$i];
				
				// A unique restricted key column
				// store it in the static configuration array
				if ($row['Non_unique'] == 0)
				{
					// Verify that the primary key is only composed of the id column
					if ($row['Key_name'] == 'PRIMARY')
					{
						if ($row['Column_name'] != 'id')
						{
							$error  = 'constructTemplate(): Cannot establish unique keys, I found that the ';
							$error .= 'primary key contains more columns then just `id` you will need to change ';
							$error .= 'that before you try to use Wallop!';
							$this->errors[] = $error;
							return false;
						}
					}
					
					$classInfoArray['uniqueKeys'][$row['Key_name']][$row['Column_name']] = true;
				}
			
				++$i;
			}
		}
		
		// At this point the object should be properly setup
		
		// Now let's deal with the column templating
		if ( !isset($this->objectInfo['templateColumns']) )
		{
			$templateColumns = array();
			if ( isset($this->objectInfo['requestedColumns']) )
			{
				foreach ($classInfoArray['columns'] as $column)
				{
					if ( isset($this->objectInfo['requestedColumns'][$column]) )
					{
						$templateColumns[] = $column;
						unset($this->objectInfo['requestedColumns'][$column]);
					}
				}
				
				if ( !empty($this->objectInfo['requestedColumns']) )
				{			
					$error  = 'constructTemplate(): There are some column names you specified in ';
					$error .= '$requestedColumns that do not exist for this object! They are: ';
					$error .= implode(' ,', $this->objectInfo['requestedColumns']);
					$this->errors[] = $error;
					return false;
				}
			}
			
			if ( isset($this->objectInfo['rejectedColumns']) )
			{
				$i = 0;
				$numTempCols = count($templateColumns);
				while ($i != $numTempCols)
				{
					if ( isset($this->objectInfo['rejectedColumns'][$templateColumns[$i]]) ) 
					{
						unset($this->objectInfo['rejectedColumns'][$templateColumns[$i]]);
						unset($templateColumns[$i]);
					}
				
					if ( empty($templateColumns) )
					{
						$templateColumns = $classInfoArray['columns'];
						$i = 0;
						$numTempCols = count($templateColumns);
						continue;
					}
					++$i;
				}
				
				if ( !empty($this->objectInfo['rejectedColumns']) )
				{
					// First see if the left over rejectedColumns
					foreach ($classInfoArray['columns'] as $column)
					{
						unset($this->objectInfo['rejectedColumns'][$column]);
					}
				
					// If there are still some left over they must not have matched any column
					if ( !empty($this->objectInfo['rejectedColumns']) )
					{
						$error  = 'constructTemplate(): There are some column names you specified in ';
						$error .= '$rejectedColumns that do not exist for this object! They are: ';
						$error .= implode(' ,', $this->objectInfo['rejectedColumns']);
						$this->errors[] = $error;
						return false;
					}
				}
			}
			
			if ( !isset($this->objectInfo['requestedColumns']) && 
			     !isset($this->objectInfo['rejectedColumns']) )
				$templateColumns = $classInfoArray['columns'];
				
			$this->objectInfo['templateColumns'] = array_values($templateColumns);
			unset($this->objectInfo['requestedColumns']);
			unset($this->objectInfo['rejectedColumns']);
		}
		
        return true;
    }

    /* Returns false if no uniqueValues is passed, and true if the intialization is successful
     * Iniatilizes the object using the $uniqueValues and $uniqueColumns to query the database and fill the 
	 * object. Initializes on the primary key if no $uniqueColumns are set.
	 * The parameters can take a single value or column and they will be converted to arrays
     */
    private function initialize($uniqueValues, $uniqueColumns = null)
    {
		// Validate parameters
        if ( !is_array($uniqueValues) )
		{
			if ( !isset($uniqueValues) || !is_scalar($uniqueValues) )
			{
				$error  = 'initialize(): Cannot initialize with the given $uniqueValue, it must not be an ';
				$error .= 'array or object!';
				$this->errors[] = $error;
				return false;
			}
			
			$uniqueValues = array($uniqueValues);
		}
		else
		{
			foreach ($uniqueValues as $index => $uniqueValue)
			{
				if ( !is_scalar($uniqueValue) )
				{
					$error  = 'initialize(): unique value at index `'.$index.'` must not be an object or ';
					$error .= 'array!';
					$this->errors[] = $error;
					return false;
				}
			}
		}
		
		if ( isset($uniqueColumns) )
		{
			if ( !is_array($uniqueColumns) )
			{
				if ( !is_string($uniqueColumns) )
				{
					$error  = 'initialize(): Cannot initialize with the given $uniqueColumn, it must be a ';
					$error .= 'string!';
					$this->errors[] = $error;
					return false;
				}
				
				$filter = '`'.$uniqueColumns.'`=? ';
				$uniqueColumns = array($uniqueColumns);
			}
			else
			{
				$filter = '';
				foreach ($uniqueColumns as $index => $uniqueColumn)
				{
					if ( !is_string($uniqueColumn) )
					{
						$error  = 'initialize(): unique column at index `'.$index.'` must be a string!';
						$this->errors[] = $error;
						return false;
					}
					
					// Also setup the query string for filter on the unique key
					if ($filter != '')
						$filter .= 'AND ';
					$filter .= '`'.$uniqueColumn.'`=? ';
				}
			}	
		}
		else
		{
			if ( count($uniqueValues) != 1 )
			{
				$error  = 'initialize(): If no uniqueColumn is set then you must only provide one unique ';
				$error .= 'value!';
				$this->errors[] = $error;
				return false;
			}
			
			$uniqueColumns = array('id');
			$filter = '`id`=? ';
		}
		
		if ( count($uniqueValues) != count($uniqueColumns) )
		{
			$error  = 'initialize(): The number of values and columns specified must be exactly the same!';
			$this->errors[] = $error;
			return false;
		}
		
		// The template of this object must be setup first
        if ( !$this->constructTemplate() )
			return false;
			
		$classInfo =& self::$classInfo[$this->objectInfo['tableName']];	
		
		$uniqueKeyName = null;
		foreach ($classInfo['uniqueKeys'] as $keyName => $keyColumns)
		{
			if ( count($keyColumns) == count($uniqueColumns) )
			{
				$allColumns = true;
				foreach ($uniqueColumns as $uniqueColumn)
				{
					if ( !isset($keyColumns[$uniqueColumn]) )
					{
						$allColumns = false;
						break;
					}
				}
				
				if ($allColumns)
				{
					$uniqueKeyName = $keyName;
					break;
				}
			}
		}
		
		if ( !isset($uniqueKeyName) )
		{
			$this->errors[] = 'initialize(): Can\'t initialize on these columns no unique key matches them!';
			return false;
		}
			
		// Okay done validation...

		$tempColumns = $this->objectInfo['templateColumns'];
			
        global $database;

        // Setup the select field
        $columns = '';
		$i = 0;
		$size = count($tempColumns);
        while ($i != $size)
		{
            $columns .= '`'. $tempColumns[$i] .'`,';
			
			++$i;
		}
        $columns = trim($columns, ',');

		$query  = 'SELECT `id`,'.$columns.' FROM `'.$this->objectInfo['tableName'].'` WHERE '.$filter;
		$query .= 'LIMIT 1';
        $result = $database->execQueryWith($query, $uniqueValues);
        if (!$result)
        {
            $error  = 'initialize(): Unable to Query record from Table \''.$this->objectInfo['tableName'];
			$error .= '\' it probably doesn\'t exist! Check the tableName you set in the constructor.';
			$this->errors[] = $error;
            return false;
        }

        $row = $database->getRow();
        if ( !$row )
        {
            $error  = 'initialize(): No record of the \''.$this->objectInfo['tableName'].'\' table matched ';
			$error .= 'the uniqueValues you specified!';
			$this->errors[] = $error;
            return false;
        }

		// The actual work
		$this->id = $row['id'];
		$i = 0;
		$size = count($tempColumns);
        while ($i != $size)
		{
            $this->record[$tempColumns[$i]] = $row[$tempColumns[$i]];
			
			++$i;
		}

        return true;
    }
	
	/* Returns the id of the object. 
     * This is automatically considered the table's primary key.
     */
    public function getId()
    {
		if ( isset($this->id) )
			return $this->id;
			
		return false;
    }

    /* Returns the array of column names
     */
    public function getColumns()
    {
        if ( !$this->constructTemplate() )
			return false;

        return self::$classInfo[$this->objectInfo['tableName']]['columns'];
    }
	
	/* Returns the array of column names for this template
     */
    public function getTemplateColumns()
    {
        if ( !$this->constructTemplate() )
			return false;

        return $this->objectInfo['templateColumns'];
    }
    // ============================================================================================
	// End of iniatlization and object info function
	
	

	// Functions related to working with this object and it's attributes
	// ============================================================================================
	
    /* Takes a column name
     * Returns the value of the column in the record if it exists
     * otherwise it sets the errors array and returns false
     */
    public function get($column)
    {
        if ( !$this->constructTemplate() )
			return false;

        // Check if column even exists
        if ( !in_array($column, self::$classInfo[$this->objectInfo['tableName']]['columns']) )
        {
            $error = 'get(): Cannot get value \''.$column.'\', that column does not exist in this class!';
			$this->errors[] = $error;
            return false;
        }

        // Return the value of the column passed in
        if( isset($this->record[$column]) )
            return $this->record[$column];
        else
            return null;
    }
    
    /* Takes a column name and a value
     * Sets that column with that value if the column exists
     * then Returns true
     * otherwise it sets errors and returns false
     */
    public function set($column, $value)
    {
		// Ensure that $value is a primitive type
		if ( !is_scalar($value) && $value != null )
		{
			$error = 'set(): Cannot set \''.$column.'\', the value provided cannot be an array or object!';
			$this->errors[] = $error;
			return false;
		}
	
        if ( !$this->constructTemplate() )
			return false;

        // Check if column even exists
        if ( !in_array($column, self::$classInfo[$this->objectInfo['tableName']]['columns']) ) 
        {
            $error = 'set(): Cannot set `'.$column.'`, that column does not exist in this object!';
			$this->errors[] = $error;
            return false;
        }
		
		if ($value == null && 
		    isset(self::$classInfo[$this->objectInfo['tableName']]['notNullColumns'][$column]) )
		{
			$error  = 'set(): Cannot set `'.$column.'` to null that would violate database restrictions ';
			$error .= 'you have setup!';
			$this->errors[] = $error;
			return false;
		}
		
		if ( !isset($this->record[$column]) || $this->record[$column] != $value)
		{
				$this->objectInfo['valueChanged'] = true;

				// Set the column to the specified value
				$this->record[$column] = $value;
		}

        return true;
    }
	
	/* Special overloadable functions in PHP
	 * whenever a member on this object attempted to be accessed that isn't accessible in the scope it was
	 * access then this function will be called. This function is also called when a non-existent member is
	 * trying to be accessed...
	 * This allows us to wrap our dumb getter and setter functions with this neat little function so the
	 * record of this object can be accessed as if this was a normal object
	 */
	public function __get($column)
	{
		// If the user overrides the get function in their class theirs will be called by this line
		$result = $this->get($column);
		if ( !$result )
		{
			trigger_error('Undefined property: '.get_class($this).'::$'.$column);
		}
		
		return $result;
	}

	public function __set($column, $value)
	{
		// Ensure that $value is a primitive type
		if ( !is_scalar($value) && $value != null)
		{
			trigger_error('Cannot assign an array or object to a Wallop record: '
			              .get_class($this).'::$'.$column);
			return false;
		}
	
		// If the user overrides the set function in their class theirs will be called by this line
		$result = $this->set($column, $value);
		if ( !$result )
		{
			if ($value == null && 
				isset(self::$classInfo[$this->objectInfo['tableName']]['notNullColumns'][$column]) )
				return false;
			else
				trigger_error('Undefined property: '.get_class($this).'::$'.$column);
		}
		
		return $result;
	}

    /* Updates the current record of the Wallop to the database, or adds it if the id of the Wallop is not
	 * set (and thus has not been pulled from the database, meaning the Wallop has yet to be stored)
     * If the object is flagged for removal then it is removed along with any composites that are only
	 * referenced by this object.
     */
    public function commit($handleRelatives = true, $commitRelatives = true, $keepRelativesOnRemove = false)
    {
        global $database;

        if ( !$this->constructTemplate() )
			return false;

		if ( !isset(self::$classInfo[$this->objectInfo['tableName']]) )
		{
			$error  = 'commit(): The configuration of this object is not setup properly check errors ';
			$error .= 'created by the constructor for more information!';
			$this->errors[] = $error;
			return false;
		}
		$classInfo =& self::$classInfo[$this->objectInfo['tableName']];
		
        // Since the array of values to set is going to be identical for both cases, we may as well assemble
		// it beforehand. Let's do that.
		$values = array();
		$i = 0;
		$numColumns = count($classInfo['columns']);
        while ($i != $numColumns)
		{
			if ( isset($this->record[$classInfo['columns'][$i]]) )
				$values[] = $this->record[$classInfo['columns'][$i]];
			
			++$i;
		}

        // If it exists in the database, build a query to update it.
        if ( isset($this->id) )
        {
            if ( isset($this->objectInfo['toBeRemoved']) && $this->objectInfo['toBeRemoved'] )
            {
                // Removes the record from the database if it is flagged to be removed
                // Calls remove on composites
				$result = self::removeRecursive($this, 0, $keepRelativesOnRemove);
				
				if ( !$result )
				{
					$error  = 'commit(): Failed to delete this object or one of it\'s composites! ';
					$error .= 'See the following for more info: ';
					$error .= self::$staticErrors[0];
					$this->errors[] = $error;
				}
				
                return $result;
            }

            // Only update the database if some value was change in the set function
            if ( isset($this->objectInfo['valueChanged']) && $this->objectInfo['valueChanged'] )
            {
                $query = 'UPDATE `'.$this->objectInfo['tableName'].'`
                          SET ';

				$i = 0;
                while ($i != $numColumns)
				{
					if ( isset($this->record[$classInfo['columns'][$i]]) )
						$query .= '`'.$classInfo['columns'][$i].'` = ?,';
					
					++$i;
				}
                $query = trim ($query, ',');

                $query .= ' WHERE `id` = ?';

                // In the case of an update, we have an extra value to account for; the id - We specify 
				// 'WHERE `id` = ?`', so now we have to append it to the end of $values if we are updating
				// (but not if we are inserting, since then there is no specification of 'WHERE `id` = ?')

                $values[] = $this->id;

                $result = $database->execQueryWith($query, $values);
				
				if ( !$result )
				{
					$error  = 'commit(): Failed to update this object! It\'s likely the tableName you ';
					$error .= 'specified in the constructor doesn\'t exist Or that you violated a unique ';
					$error .= 'constraint! ';
					$error .= 'Explaination: '.$database->error;
					$this->errors[] = $error;
					return false;
				}
            }
        }

        // If it does not exist in the database, build a query to add it.
        else
        {
            $query = 'INSERT INTO `'.$this->objectInfo['tableName'].'`
                      (';

			$i = 0;
			$valuePortion = '';
            while ($i != $numColumns)
			{
				if ( isset($this->record[$classInfo['columns'][$i]]) )
				{
					$query .= '`'.$classInfo['columns'][$i].'`,';
					$valuePortion .= '?,';
				}
				
				++$i;
			}
            $query = trim ($query, ',');
			$valuePortion = trim ($valuePortion, ',');

            $query .= ') VALUES ('.$valuePortion.')';

			$this->id = $database->execQueryWith($query, $values);
			if (!$this->id)
			{
				unset($this->id);
				
				$error  = 'commit(): There was an error when trying to insert the new object! ';
				$error .= 'It\'s likely the tableName you specified in the constructor doesn\'t exist Or ';
				$error .= 'that you violated a unique constraint! ';
				$error .= 'Explaination: '.$database->error;
				$this->errors[] = $error;
				return false;
			}
        }
		
		unset($this->objectInfo['valueChanged']);

		// End the function before checking relatives if this is only a simple commit
		if (!$handleRelatives)
			return true;
		
		// Handle relatives of this object
		$relationTypes = array('aggregates' => &$this->aggregates, 
		                       'composites' => &$this->composites);
        foreach ($relationTypes as $relationTypeName => &$relationType)
		{
			foreach ($relationType as $relationName => &$relation)
			{
				$relationData = $this->findRelationData($relationName);
				
				// If this relation has no objects to handle, then just ignore it
				if ( !$this->relationHasObjectsToHandle($relation) )
					continue;
				
				$relationTemplate = new $relationData['className']();
				if ( !$relationTemplate instanceof Wallop )
				{
					$error  = 'commit(): Cannot continue with relation \''.$relationName.'\' ';
					$error .= 'the relation does not inherit Wallop!';
					$this->errors[] = $error;
					continue;
				}
				
				if ( !empty($relationTemplate->errors) )
				{
					$error  = 'commit(): Cannot continue with relation \''.$relationName.'\' ';
					$error .= 'the relation does not construct without error, check it\'s configuration! ';
					$error .= 'The following error is the relation\'s error:';
					$this->errors[] = $error;
					$this->errors[] = $relationTemplate->errors[0];
					continue;
				}
			
				// Commit uninitialized objects and add them to the objects and newObjects arrays
				if ( $commitRelatives && isset($relation['uninitializedNewObjects']) )
				{
					// Commit newObjects before we add uninitializedObjects to the
					// newObjects array (structurally necessary)
					if ( isset($relation['newObjects']) )
					{
						foreach ($relation['newObjects'] as $newId => $unused)
						{
							$relation['objects'][$newId]->commit(true, true);
						}
					}
				
					// Commit uninitializedObjects and add them to the newObjects array
					// so they can have their record on the relationTable setup
					$i = 0;
					$numObjs = count($relation['uninitializedNewObjects']);
					while ($i != $numObjs)
					{					
						$relative = $relation['uninitializedNewObjects'][$i];
					
						if ( $relative->commit(true, true) )
						{
							$id = $relative->id;
							$relation['objects'][$id]	= $relative;
							$relation['newObjects'][$id] = true;
							
							unset($relation['uninitializedNewObjects'][$i]);
						}
						else
						{
							$relativeErrors = $relative->getErrors();
							$error  = 'commit(): A problem occurred when trying to commit an uninitialized';
							$error .= ' object of type '.$relationName.' with the following';
							$error .= ' error: "'.$relativeErrors[0].'"';					
							$this->errors[] = $error;
						}
						
						++$i;
					}
				}
			
				// In either of the below cases we will need to initialize some values
				if( isset($relation['objects']) || isset($relation['removedObjects']) )
				{
					$relationColumns = $this->generateRelationColumns($relationName, $relationTemplate);
					
					if ( !$relationColumns )
					{
						end($this->errors);
						$this->errors[key($this->errors)] = 'commit(): '.$this->errors[key($this->errors)];
						return false;
					}
					
					$thisColumn = $relationColumns['this'];
					$relativeColumn = $relationColumns['relative'];
				}
				else
					continue;
					
				
				// If they are any objects marked for removal
				if ( isset($relation['removedObjects']) )
				{
					// Create the string filled with ids of objects to remove
					// from the relation table between this object and this relation
					$compsToRemove = array();
					$numToRemove = 0;
					$idsToRemove = '(';
					foreach ($relation['removedObjects'] as $id => $unused)
					{				
						$isStored = isset($relation['objects'][$id]);
						$removedStored = isset($relation['removedStoredObjects'][$id]);
						if ( !$isStored || $removedStored )
						{
							++$numToRemove;
							$idsToRemove .= $id.',';
							
							// Add ids to remove array for the recursive removal
							if ($relationTypeName == 'composites')
								$compsToRemove[] = $id;
							
							// Also remove the relative internally from this object if it's stored
							if ($isStored && $removedStored)
							{
								unset($relation['objects'][$id]);
								unset($relation['newObjects'][$id]);
								
								if ( isset($relation['storedGets']) )
								{
									$i = 0;
									$numStoredGets = count($relation['storedGets']);
									while ($i != $numStoredGets)
									{
										unset($relation['storedGets'][$i]['mapOfIds'][$id]);
										
										++$i;
									}
								}
							}
						}
					}
					$idsToRemove = trim($idsToRemove, ',') . ')';
										
					// If the relation is composite try to remove this object
					$compIds = array();
					if ($relationTypeName == 'composites')
					{
						$compIds = self::hasNoCompositeRelatives($relationTemplate, $relativeColumn,
						                                         $relationName, $this->id, $thisColumn, 
																 $compsToRemove);
																 
						if ( !$compIds )
						{
							$error  = 'commit(): Failed to retrieve information about what composites to ';
							$error .= 'remove. Because: ';
							$error .= self::$staticErrors[0];
							$this->errors[] = $error;
							return false;
						}
					}
					
					// Create the query to remove relatives from the relation table
					$query  = 'DELETE FROM `'.$relationName.'` ';
					$query .= 'WHERE `'.$relativeColumn.'` IN '.$idsToRemove.' && `'.$thisColumn.'`=';
					$query .= $this->id.' LIMIT '.$numToRemove;
					$result = $database->execQuery($query);
					
					// Ensure the database query executed as expected
					if ( !$result )
					{
						$error  = 'commit(): The query to delete the records of the relation table between ';
						$error .= 'the relation `'.$relationName.'` and this object has failed! ';
						$error .= 'This is likely due to an incorrect database setup or wrong values ';
						$error .= 'specified in the constructor. Check to make sure the column names ';
						$error .= 'of the relation table `'.$relationName.'` are setup ';
						$error .= 'setup properly and that the table exists';
						$this->errors[] = $error;
						return false;
					}
					
					// After the deletion of the relation table records
					if ($relationTypeName == 'composites' && !empty($compIds))
					{
						$size = count($compIds);
						$i = 0;
						while ($i != $size)
						{
							$result = self::removeRecursive($relationTemplate, $compIds[$i], false);
							
							if ( !$result )
							{
								$error  = 'commit(): Failed to delete this composite relation: ';
								$error .= '`'.$relationName.' See the following for more ';
								$error .= 'info: ';
								$error .= self::$staticErrors[0];
								$this->errors[] = $error;
							}
							
							++$i;
						}
					}
					
					unset($relation['removedObjects']);
					unset($relation['removedStoredObjects']);
				}

				// If there are any objects stored for this relation
				if ( isset($relation['objects']) )
				{
					// MUST BE AFTER the 'removedObjects' block
					if ( isset($relation['newObjects']) )
					{
						// Build the inlist for the stored get check at the same time here
						$newInList = '(';
						
						// Search for newObjects to add relations for in the database
						// and add a relation in the database for them
						$query  = 'INSERT INTO `'.$relationName.'` ';
						$query .= '(`'.$relativeColumn.'`,`'.$thisColumn.'`) VALUES';
						foreach ($relation['newObjects'] as $newId => $unused)
						{
							$query .= '('.$newId.','.$this->id.'),';
							
							$newInList .= $newId.',';
							
							// Also remove the new relative from the stored relatives list
							unset($relation['objects'][$newId]);
						}
						$query = trim($query, ',');
						
						$result = $database->execQuery($query);
						
						// Ensure the database query executed as expected
						if ( !$result )
						{
							$error  = 'commit(): Inserting new relatives into the database for relation ';
							$error .= '`'.$relationName.'` failed! ';
							$error .= 'This is likely due to an incorrect database setup or wrong values ';
							$error .= 'specified in the constructor. Check to make sure the column names ';
							$error .= 'of the relation table `'.$relationName.'` are ';
							$error .= 'setup properly and that the table exists';
							$this->errors[] = $error;
							return false;
						}
						
						// Iterate through storedGets and remove them if their result set changed
						if ( isset($relation['storedGets']) )
						{
							$newInList = trim($newInList, ',').')';
						
							$i = 0;
							$numStoredGets = count($relation['storedGets']);
							while ($i != $numStoredGets)
							{
								if ( $this->checkNewAgainstStoredGet($relationTemplate, $relationName,
														             $relation['storedGets'][$i], $newInList)
									)
								{
									// Go through all objects referenced by this stored get and remove them
									// from the object if they are not used by another array mapping
									foreach ($relation['storedGets'][$i]['mapOfIds'] as $relId => $unused)
									{
										if ( !isset($relation['modifiedObjects'][$relId]) &&
										     !isset($relation['removedStoredObjects']) )
											unset($relation['objects'][$relId]);
									}
									unset($relation['storedGets'][$i]);
								}
									
								++$i;
							}
						}
						
						unset($relation['newObjects']);
					}
								
					// TODO: If decide to commit objects that are disassociated then move this block
					//       of code above the isset('removedObjects') block
					//       Otherwise leave it here
					if ( $commitRelatives && isset($relation['modifiedObjects']) )
					{
						foreach ($relation['modifiedObjects'] as $objId => $unused)
						{
							$relation['objects'][$objId]->commit(true, $commitRelatives);
							
							if ( isset($relation['storedGets']) )
							{
								// Now unset this object if it's not mapped in a storedGet
								$found = false;
								$i = 0;
								$numStoredGets = count($relation['storedGets']);
								while ($i != $numStoredGets)
								{
									if ( isset($relation['storedGets']['mapOfIds'][$objId]) )
									{
										$found = true;
										break;
									}
									
									++$i;
								}
								
								if ( !$found )
									unset($relation['objects'][$objId]);
							}
						}
						
						unset($relation['modifiedObjects']);
						unset($relation['originalObjects']);
					}					
				}
			}
		}
		
		// End of commit...
		return true;
    }
	
	/* Returns true if the given storedGet is found to have a result set that
	 * encompasses any of the newObjects
	 * This is a helper function specifically made to make the code cleaner in commit()
	 */
	private function checkNewAgainstStoredGet(Wallop $newObjTemplate, $newRelationTableName,
											  array &$storedGet, $newInList)
	{
		// Create the query to check for the newObjects in the result set
		// of the storedGet's settings
		
		// Generate Column names
		$relationColumns = $this->generateRelationColumns($newRelationTableName, $newObjTemplate);
		if ( !$relationColumns )
		{
			end($this->errors);
			$this->errors[key($this->errors)] = 'commit(): '.$this->errors[key($this->errors)];
			return false;
		}

		$thisColumn = $relationColumns['this'];
		$newObjColumn = $relationColumns['relative'];

		// We can assume that the storedGets's values are in the expected range
		// So no validation is needed
		
        $query = 'SELECT MAX(other.`id`) IS NOT NULL AS anyResults ';
		$query .= 'FROM `'.$newObjTemplate->objectInfo['tableName'].'` AS other,';
            $query .= '(SELECT `'.$newObjColumn.'` FROM ';
            $query .= '`'.$newRelationTableName.'` ';
            $query .= 'WHERE `'.$thisColumn.'`='.$this->id.' LIMIT '.$storedGet['start'].',';
			$query .= $storedGet['amount'].') AS ids ';
        $query .= 'WHERE other.`id`=ids.`'.$newObjColumn.'` AND other.`id` IN '.$newInList;
		
		// If the extra where clause is used then add it to the end of the query
		if ($storedGet['conditions'] != '')
			$query .= ' AND '. $storedGet['conditions'];
		
		// If a column to sort by is specified then add it to the end of the query
		if ($storedGet['sortBy'] != '')
		{
			$query .= ' ORDER BY other.`'.$storedGet['sortBy'].'` '.$storedGet['sort'];
		}

			
        global $database;
        $result = $database->execQuery($query);
		
        if (!$result)
        {
            $error  = 'checkNewAgainstStoredGet(): Could not query the database to get relatives!';
			$error .= 'This is likely due to an incorrect database setup or wrong values ';
			$error .= 'specified in the constructor. Check to make sure the column names ';
			$error .= 'of the relation table `'.$relationName.'` are setup ';
			$error .= 'setup properly and that the table exists';
			$this->errors[] = $error;
            return false;
        }
		
		$row = $database->getRow();
		if (!$row)
		{
			$error  = 'checkNewAgainstStoredGet(): Apparently my private function can\'t do a private ';
			$error .= 'function without failing. But that\'s not my fault, you better fix it!';
			$this->errors[] = $error;
			
			// Return true because then the storedGet won't be removed.
			return true;
		}
		
		return $row['anyResults'];
	}

    /* Flags this object for removal from the database
     */
    public function remove()
    {
        if ( !isset($this->id) )
        {
            $this->errors[]  = 'remove(): Cannot remove this object, it must be initialized first!';
            return false;
        }

        $this->objectInfo['toBeRemoved'] = true;

        return true;
    }

    /* If this object was flagged for removal then it disables that flag
     * If this object was not flagged this will return false and set an error
     */
    public function unremove()
    {
        if ( !isset($this->objectInfo['toBeRemoved']) || !$this->objectInfo['toBeRemoved'] )
        {
            $error  = 'unremove(): Cannot unremove this object, it is not already flagged for removal!';
			$this->errors[] = $error;
            return false;
        }

        unset($this->objectInfo['toBeRemoved']);

        return true;
    }

    /* Returns the array of errors
     * and unsets the stored errors
     */
    public function getErrors($clearErrors = true)
    {
        $temp = $this->errors;
        if($clearErrors)
        {
            $this->errors = array();
        }
        return $temp;
    }
	
	/* Returns true if this object violates unique constraints
	 * If any of the unique keys will be violated by this object
	 */
	public function isViolatingUnique()
	{
		if ( !$this->constructTemplate() )
			return false;
	
		// Go through each unique key and build a single query to test for violations
		$query = '';
		$values = array();
		foreach (self::$classInfo[$this->objectInfo['tableName']]['uniqueKeys'] 
		      as $uniqueKeyName => $uniqueColumns)
		{
			if ($uniqueKeyName == 'PRIMARY')
				continue;
		
			if ($query != '')
				$query .= 'OR ';
			$query .= '(';
			$inner = '';
			foreach ($uniqueColumns as $uniqueColumn => $unused)
			{
				if ($uniqueColumn == 'id')
					continue;
					
				if ($inner != '')
					$inner .= 'AND ';	
				$inner .= '`'.$uniqueColumn.'`=? ';
	
				$values[] = $this->record[$uniqueColumn];
			}
			
			$query .= $inner.') ';
		}
		
		// Run the query
		$query = 'SELECT null FROM `'.$this->objectInfo['tableName'].'` WHERE '.$query;
		if ( isset($this->id) )
		{
			$query .= ' AND `id` != ?';
			$values[] = $this->id;
		}
		
		global $database;
		$result = $database->execQueryWith($query, $values);
		
		if (!$result)
		{
			$error  = 'isViolatingUnique(): Could query database to check for unique violations this is ';
			$error .= 'most likely due to the tableName you specified in the constructor not existing!';
			$this->errors[] = $error;
			return false;
		}
		
		$row = $database->getRow();
		
		return ($row ? true : false);
	}
	// ============================================================================================
	// End of functions relating to modifying this object
	
	
	
	// Functions related to relations
	// ============================================================================================

	/* Returns all of the relatives of type $relationName that are not committed yet
	 * This function does not query the database for additional relatives
	 */
	public function getNewRelatives($relationName)
	{
		if ( $relationName == '' || !is_string($relationName) )
        {
            $error = 'getNewRelatives(): must take a non-null $relationName that is a string!';
			$this->errors[] = $error;
            return false;
        }
		
		// Ensure that the relation exists in this object
		// Whilst at the same time setting the targetArray for later use
		$relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			end($this->errors);
			$index = key($this->errors);
			
			$this->errors[$index] = 'getNewRelatives(): '.$this->errors[$index];
			return false;
		}
		$relationData = $relationInfo[0];
		$relationArray =& $relationInfo[1];
		
		//Assume the relationName is validated
        $relativeTemplate = new $relationData['className']();
        if ( !$relativeTemplate->constructTemplate() )
        {
            $error  = 'getNewRelatives(): Could not construct a template of \''.$relationName.'\' because: ';
			$error .= $relativeTemplate->errors[0];
			$this->errors[] = $error;
            return false;
        }
		if ( !empty($relationTemplate->errors) )
		{
			$error  = 'getNewRelatives(): Cannot continue with relation ';
			$error .= '\''.$relationName.'\', the relation does not construct without error, check ';
			$error .= 'it\'s configuration! The following Error is the relation\'s error:';
			$this->errors[] = $error;
			$this->errors[] = $relationTemplate->errors[0];
			return false;
		}
		
		// Get the ids of the uncomitted relatives from the objects array
		$relatives = array();
		
		if ( isset($relationArray['newObjects']) )
		{
			foreach ($relationArray['newObjects'] as $id => $unused)
			{
				$relatives[] = $relationArray['objects'][$id];
			}
		}
		
		if ( isset($relationArray['uninitializedNewObjects']) )
		{
			$i = 0;
			$numUninitialized = count($relationArray['uninitializedNewObjects']);
			while ($i != $numUninitialized)
			{
				$relatives[] = $relationArray['uninitializedNewObjects'][$i];
				
				++$i;
			}
		}
		
		return $relatives;
	}
	
	/* Returns an $amount of relatives of $relationName starting from $start 
	 * with conditions $where and sort order $sort on column $sortBy
	 * When the $storedRelatives flag is set to true then we first store the objects we retrieved 
	 * in this object
     */
    public function getRelatives($relationName, $start = 0, $amount = -1, $sortBy = '', $sort = 'ASC',
								 $conditions = '', $storeRelatives = false, $replaceWithModified = false)
    {
		// If the object is uninitialized...
		if ( !isset($this->id) || $this->id == '' || intval($this->id) < 1)
        {
            $error  = 'getRelatives(): Cannot retrieve relatives when this object is not initialized! ';
			$error .= 'If you want to retrieve relatives stored in this object (and not from the ';
			$error .= 'database) then use the getNewRelatives() function ';
			$this->errors[] = $error;
            return false;
        }

        if ( $relationName == '' || !is_string($relationName) )
        {
            $error  = 'getRelatives(): must take a non-null $relationName that is not an array';
			$error .= ' or object!';
			$this->errors[] = $error;
            return false;
        }
       
        // Ensure that the relation exists in this object
		// Whilst at the same time setting the targetArray for later use
        $relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			end($this->errors);
			$index = key($this->errors);
			
			$this->errors[$index] = 'getRelatives(): '.$this->errors[$index];
			return false;
		}
		$relationData = $relationInfo[0];
		$relationArray =& $relationInfo[1];
		
        //Assume the relationName is validated
        $relativeTemplate = new $relationData['className']();
        if ( !$relativeTemplate->constructTemplate() )
        {
			$this->errors[] = 'getRelatives(): Could not construct a template of \''.$relationName.'\'!';
			$this->errors[] = 'getRelatives: Errors after this are for $relativeTemplate:';
            $this->errors = array_merge($this->errors, $relativeTemplate->getErrors());
            $this->errors[] = 'End of errors from $relativeTemplate';
            return false;
        }
		if ( !empty($relationTemplate->errors) )
		{
			$error  = 'getRelatives():  Cannot continue with relation \''.$relationName.'\' ';
			$error .= 'the relation does not construct without error, check it\'s configuration!';
			$error .= 'The following error is the relation\'s error:';
			$this->errors[] = $error;
			$this->errors[] = $relationTemplate->errors[0];
			return false;
		}
		
		$relativeColumns = self::$classInfo[$relativeTemplate->objectInfo['tableName']]['columns'];
		
		// Setup our existing parameters for use below
		// Validate $start
		if ( !is_numeric($start) )
		{
			$this->errors[] = 'getRelatives(): $start must be a numeric type!';
			return false;
		}
		$start = intval($start);
        if ($start < 0)
        {
			$this->errors[] = 'getRelatives(): You cannot give a $start value that is less than 0!';
			return false;
		}
		
		// Validate $amount
		if ( !is_numeric($amount) )
		{
			$this->errors[] = 'getRelatives(): $amount must be a numeric type!';
			return false;
		}
        $amount = intval($amount);
		if ($amount < -1)
		{
			$this->errors[] = 'getRelatives():  $amount cannot be less than -1!';
		}
        if ($amount == -1)
            $amount = '18446744073709551615';
		
		// Validate $sortBy and $sort only if $sortBy is actually set 
		if ($sortBy != '')
		{
			if ( !is_string($sortBy) || !in_array($sortBy, $relativeColumns))
			{
				$this->errors[] = 'getRelatives(): $sortBy must a string and must specify a column!';
				return false;
			}
		
			// Make sure $sortBy is a column in the relatives table
			if ( strtoupper($sort) != 'ASC' && strtoupper($sort) != 'DESC')
			{
				$this->errors[] = 'getRelatives(): $sort must either be \'ASC\' or \'DESC\'!';
				return false;
			}
		}
		
		//  Validate $conditions
		if ( !is_string($conditions) )
		{
			$this->errors[] = 'getRelatives(): $conditions must be a string!';
			return false;
		}
				
		// First check to see if this query has been made already
		if ( isset($relationArray['storedGets']) )
		{
			$i = 0;
			$size = count($relationArray['storedGets']);
			while ($i != $size)
			{
				$storedGet = $relationArray['storedGets'][$i];
				
				if ($storedGet['start'] != $start || $storedGet['amount'] != $amount
				 || $storedGet['sortBy'] != $sortBy || $storedGet['sort'] != $sort
				 || $storedGet['conditions'] != $conditions)
				{
					++$i;
					continue;
				}
					
				// At this point this we are sure that this $storedGet is the same as
				// this call so we merge it's id map to get the relatives we want
				$relatives = array();

				foreach ($storedGet['mapOfIds'] as $id => $unused)
				{
					if ( !$replaceWithModified && isset($relationArray['originalObjects'][$id]) )
						$relatives[] = $relationArray['originalObjects'][$id];
					else
						$relatives[] = $relationArray['objects'][$id];
				}
				
				// Then return them
				return $relatives;
			}
		}

		
		// Create the query to get the relatives
		$relationTableName = $this->findRelationTableName($relationName);
		
		// Generate the column names
		$relationColumns = $this->generateRelationColumns($relationName, $relativeTemplate);					
		if ( !$relationColumns )
		{
			end($this->errors);
			$this->errors[key($this->errors)] = 'commit(): '.$this->errors[key($this->errors)];
			return false;
		}

		$thisColumn = $relationColumns['this'];
		$relativeColumn = $relationColumns['relative'];

        $query = 'SELECT `id`,';
		$i = 0;
		$size = count($relativeColumns);
        while ($i != $size)
		{
            $query .= '`'.$relativeColumns[$i].'`,';
			
			++$i;
		}
        $query = trim($query, ',');

        $query .= ' FROM `'.$relativeTemplate->objectInfo['tableName'].'` AS other,';
            $query .= '(SELECT `'.$relativeColumn.'` FROM ';
            $query .= '`'.$relationTableName.'` ';
            $query .= 'WHERE `'.$thisColumn.'`='.$this->id.') ';
            $query .= 'AS ids ';
        $query .= 'WHERE other.`id`=ids.`'.$relativeColumn.'`';
		
		// If the extra where clause is used then add it to the end of the query
		if ($conditions != '')
			$query .= ' AND '. $conditions;
		
		// If a column to sort by is specified then add it to the end of the query
		if ($sortBy != '')
		{
			$query .= ' ORDER BY other.`'.$sortBy.'` '.$sort;
		}
		
		// Apply $start and $amount to the query
		$query .= ' LIMIT '.$start.','.$amount;
		
        global $database;
        $result = $database->execQuery($query);
		
        if (!$result)
        {
            $error  = 'getRelatives(): Could not query the database to get relatives! Because: ';
			$error .= $database->error.' ';
			$error .= 'This is likely due to a mismatch between the database structure and the columns ';
			$error .= 'and/or table names you specified in the constructor of this object.';
			$this->errors[] = $error;
            return false;
        }

        $rows = $database->getAllRows();
        if (!$rows)
        {	
			// It might just be empty
			if ( is_array($rows) )
				return array();
							
            $error = 'getRelatives(): Failed to get the records of the relatives from the database!';
			$this->errors[] = $error;
            return false;
        }
		
		// Create the relatives in object form
		$relatives = array();
		$i = 0;
		$numRows = count($rows);
        while ($i != $numRows)
        {
			// If the flag to replace the relative with internal is set then replace
			if ( $replaceWithModified && 
			     isset($relationArray['modifiedObjects'][$rows[$i]['id']]) )
			{
				$relativeTemplate = $relationArray['objects'][$rows[$i]['id']];
			}
			else
			{
				$colId = 0;
				$numCols = count($relativeColumns);
				while ($colId != $numCols)
				{
					$relativeTemplate->id = $rows[$i]['id'];
					
					$relativeTemplate->record[$relativeColumns[$colId]] 
						= $rows[$i][$relativeColumns[$colId]];
					
					++$colId;
				}
			}

            // store relatives by their index from the query
            $relatives[] = clone $relativeTemplate;
			
			++$i;
        }
	
	
		// If the storeRelatives flag is set to true
		// Then store the objects
		if ($storeRelatives)
		{
			// Stored the relatives retrieved
			$mapOfIds = array();
			$i = 0;
			$numRelatives = count($relatives);
			while ($i != $numRelatives)
			{
				$relative = $relatives[$i];
				$relId = $relative->id;
			
				// If this relative is already stored here don't overwrite
				if ( !isset($relationArray['objects'][$relId]) )
					$relationArray['objects'][$relId] = $relative;
					
				// If this relative is already being stored as a modified object
				// we need to save this relative in the originalObjects array
				if ( isset($relationArray['modifiedObjects'][$relId]) &&
				     !isset($relationArray['originalObjects'][$relId]) )
					$relationArray['originalObjects'][$relId] = $relative;
					
				$mapOfIds[$relative->id] = true;
				
				++$i;
			}
			
			$storedGet = array();
			$storedGet['start']      = $start;
			$storedGet['amount']     = $amount;
			$storedGet['sortBy']     = $sortBy;
			$storedGet['sort']       = $sort;
			$storedGet['conditions'] = $conditions;
			$storedGet['mapOfIds']   = $mapOfIds;
			
			$relationArray['storedGets'][] = $storedGet;
		}
	
		// Finally just simply return the objects we created
		return $relatives;
    }
	
	/* Takes a relation name to remove as it's optional parameter
	 * This function removes all relatives that have been stored in this object by the getRelatives
	 * function. The purpose to allow the user to make this object as slim as possible at any moment
	 */
	public function unsetStoredRelatives($relationToRemove = null, $unsetAll = false)
	{
		if ( isset($relationToRemove) && !is_string($relationToRemove) )
		{
			$error  = 'unsetStoredRelatives(): You must provide either a string of the relation name or ';
			$error .= 'null for the first argument!';
			$this->errors[] = $error;
			return false;
		}
	
		// An anonymous function used to keep this function effecient and yet
		// unify the code into one block
		$unsetRelationFunc = function (&$relation) use ($unsetAll)
		{
			if ($unsetAll)
			{
				unset($relation['objects']);
				unset($relation['modifiedObjects']);
				unset($relation['newObjects']);
				unset($relation['removedStoredObjects']);
				unset($relation['uninitializedNewObjects']);
			}
			else
			{
				if ( isset($relation['objects']) )
				{
					foreach ($relation['objects'] as $objId => $unused)
					{
						if ( !isset($relation['modifiedObjects'][$objId]) &&
							 !isset($relation['newObjects'][$objId]) )
						{
							unset($relation['objects'][$objId]);
						}
					}
				}
			}
					
			unset($relation['storedGets']);
			unset($relation['originalObjects']);
		};
		
		$relationTypes = array();
		$relationTypes[] = &$this->aggregates;
		$relationTypes[] = &$this->composites;
		
		if ( isset($relationToRemove) )
		{
			$relationInfo = $this->findRelation($relationToRemove);
			if ( !$relationInfo )
			{
				end($this->errors);
				$index = key($this->errors);
				
				$this->errors[$index] = 'unsetStoredRelatives(): '.$this->errors[$index];
				return false;
			}
			$relation =& $relationInfo[1];
			
			$unsetRelationFunc($relation, $unsetAll);
		}
		else
		{
			foreach ($relationTypes as &$relationType)
			{
				foreach ($relationType as &$relation)
				{	
					$unsetRelationFunc($relation, $unsetAll);
				}
			}
		}
		
		return true;
	}
	
    /* Takes a type of Wallop and an array of Wallops of that type
     * Adds them to the appropriate array in this Wallop
     * (i.e. either $aggregates or $composites)
     */
    public function setRelatives($relationName, $relatives)
    {
		// Convert relatives to an array if it's not already an array
		if ( !is_array($relatives) )
		{
			$relatives = array($relatives);
		}
		
		if ( empty($relatives) )
		{
			return true;
		}
	
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'setRelatives(): $relationName must be a non-empty string!';
			return false;
		}
	
        $relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			end($this->errors);
			$index = key($this->errors);
			
			$this->errors[$index] = 'setRelatives(): '.$this->errors[$index];
			return false;
		}
		$relationData = $relationInfo[0];
		$relationArray =&$relationInfo[1];

		// Get an array of relatives and if they exist for this object in the database
		$arrHasRelatives = $this->hasRelatives($relationName, $relatives, false, true);
		if ( is_bool($arrHasRelatives) && !$arrHasRelatives )
		{
			$error = 'setRelatives(): hasRelatives() failed to check relatives! See previous errors.';
			$this->errors[] = $error;
			return false;
		}
		
        foreach ($relatives as &$relative)
        {
            // Make sure this Wallop is an instance of the Wallop type they specified.
            if ( !($relative instanceof $relationData['className']) )
            {
                $this->errors[] = 'setRelatives(): Tried to add a Wallop of the wrong type!';
                continue;
            }

			// If the relative is initialized then add it normally
			if ( isset($relative->id) )
			{
				$id = $relative->id;
				
				// If we already have a Wallop with this ID in here
				if ( isset($arrHasRelatives[$id]) )
				{
					// Only add a mapping to the 
					if ( !isset($relationArray['newObjects'][$id]) )
						$relationArray['modifiedObjects'][$id] = true;
				}
				else
				{
					$relationArray['newObjects'][$id] = true;
				}
				
				// Remove any removal marks for this relative
				if ( isset($relationArray['removedObjects']) )
					unset($relationArray['removedObjects'][$relative->id]);
				if ( isset($relationArray['removedStoredObjects']) )
					unset($relationArray['removedStoredObjects'][$relative->id]);
				
				// Before we override the object at this id, check if a storedGet maps to it
				if ( !isset($relationArray['originalObjects'][$id]) && isset($relationArray['storedGets']) )
				{
					// Check all storedGets arrays for this id in it's map
					$found = false;
					$i = 0;
					$numOfStoredGets = count($relationArray['storedGets']);
					while ($i != $numOfStoredGets)
					{
						if ( isset($relationArray['storedGets'][$i]['mapOfIds'][$id]) )
						{
							$found = true;
							break;
						}
						
						++$i;
					}
					
					if ($found)
					{
						$relationArray['originalObjects'][$id] = $relationArray['objects'][$id];
					}
				}
				
				$relationArray['objects'][$id] = $relative;
			}
			// Otherwise add the relative to the list of uninitialized objects
			else
			{
				$relationArray['uninitializedNewObjects'][] = $relative;
			}
        }
		unset($relative);
		
		return true;
    }

    /* Removes relations based on the relation and array of objects provided
     */
    public function removeRelatives( $relationName, $relatives = array() )
    {
		// Convert relatives to an array if it's not already an array
		if ( !is_array($relatives) )
		{
			$relatives = array($relatives);
		}
	
		if ( empty($relatives) )
		{
			return true;
		}
	
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'removeRelatives(): $relationName must be a non-empty string!';
			return false;
		}

		// Set the target array to the dependency of this relative
		$relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			end($this->errors);
			$index = key($this->errors);
			
			$this->errors[$index] = 'removeRelatives(): '.$this->errors[$index];
			return false;
		}
		$relationData = $relationInfo[0];
		$relationArray =&$relationInfo[1];
			
        foreach($relatives as &$relative)
        {
			// If this relative isn't a Wallop object
			// Ignore it but notify
			if ( !$relative instanceof Wallop )
			{
				$this->errors[] = 'removeRelatives(): Cannot remove relative it is not a Wallop object!';
				continue;
			}
			if ( !isset($relative->id) )
			{
				$this->errors[] = 'removeRelatives(): Cannot remove relative it is not initialized!';
				continue;
			}
		
			$relId = $relative->id;
		
			// If this relative isn't of the type of relation specified
			// Ignore it but notify
			if ( !$relative instanceof $relationData['className'] )
			{
				$error  = 'removeRelatives(): Cannot remove relative with id = '.$relId.' it is not belong ';
				$error .= 'to the \''.$relationName.'\' relation!';
				$this->errors[] = $error;
				continue;
			}

			if ( isset($this->id) || isset($relationArray['newObjects'][$relId]) )
			{
				$relationArray['removedObjects'][$relId] = true;
			
				if ( isset($relationArray['objects'][$relId]) )
					$relationArray['removedStoredObjects'][$relId] = true;
			}
        }
		unset($relative);
		
		return true;
    }

    /* Returns an array of id=>true/false
     * (if the object's id exists in the database)
     */
    public function hasRelatives($relationName, $relatives, $checkStoredRelatives = false, 
	                               $returnOnlyTrue = false)
    {    
		// Convert relatives to an array if it's not already an array
		if ( !is_array($relatives) )
		{
			$relatives = array($relatives);
		}
		
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'hasRelatives(): $relationName must be a non-empty string!';
			return false;
		}
	
		if ( empty($relatives) )
			return array();
	
        $relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			end($this->errors);
			$index = key($this->errors);
			
			$this->errors[$index] = 'hasRelatives(): '.$this->errors[$index];
			return false;
		}
		$relationData = $relationInfo[0];
		$relationArray =& $relationInfo[1];
		
		$relativeTemplate = new $relationData['className']();
        if (!$relativeTemplate instanceof Wallop)
        {
            $error = 'hasRelatives(): cannot take a classname that does not inherit from Wallop!';
			$this->errors[] = $error;
            return false;
        }

		$output = array();
		if ( isset($this->id) )
		{
			$relationTableName = $this->findRelationTableName($relationName);

			// Generate column names
			$relationColumns = $this->generateRelationColumns($relationName, $relativeTemplate);					
			if ( !$relationColumns )
			{
				end($this->errors);
				$this->errors[key($this->errors)] = 'commit(): '.$this->errors[key($this->errors)];
				return false;
			}

			$thisColumn = $relationColumns['this'];
			$relativeColumn = $relationColumns['relative'];

			// Create an in list of relative's ids
			$inList = '(';
			foreach($relatives as $key => &$relative)
			{
				if ( !is_object($relative) || !$relative instanceof Wallop )
				{
					$error  = 'hasRelatives(): $relatives array must only contain Wallop inheriting ';
					$error .= 'objects!';
					$this->errors[] = $error;
					return false;
				}
			
				$id = $relative->getId();
				
				if ( !$id )
				{
					// If the flag $returnOnlyTrue is set, no reason to give an error
					if ( !$returnOnlyTrue )
					{
						$error  = 'hasRelatives(): Object passed to hasRelatives() of type '.$relationName;
						$error .= ' is not initialized!';
						$this->errors[] = $error;
					}
					
					// So it doesn't effect later code
					unset($relatives[$key]);
					continue;
				}

				if ( !$relative instanceof $relationData['className'])
				{
					$error = 'hasRelatives(): Object with id='.$id.' is not of type '.$relationName.'!';
					$this->errors[] = $error;
					
					if ( !$returnOnlyTrue )
						$output[$id] = false;
						
					// So it doesn't effect later code
					unset($relatives[$key]);
					continue;
				}
				
				if ( !$returnOnlyTrue )
					$output[$id] = false;
					
				$inList .= $id.',';
			}
			unset($relative);
			$inList = trim($inList, ',').')';
			
			// If the inList is empty...
			if ( $inList == '()' )
			{
				$rows = array();
			}
			else
			{
				$query  = 'SELECT `'.$relativeColumn.'` AS id FROM `'.$relationTableName.'`';
				$query .= ' WHERE `'.$thisColumn.'`='.$this->id.' AND `'.$relativeColumn.'` IN '.$inList;

				global $database;
				
				if ( !$database->execQuery($query) )
				{
					$error  = 'hasRelatives(): The query to check the database failed -- ';
					$error .= $database->error.' This was likely due to a mismatch between the database ';
					$error .= 'structure and the columns and/or table name you specified in the ';
					$error .= 'constructor	of this object!';
					$this->errors[] = $error;
					return false;
				}
				$rows = $database->getAllRows();

				if (!$rows || !is_array($rows))
					$rows = array();
			}
			
			$i = 0;
			$numRows = count($rows);
			while ($i != $numRows)
			{
				$output[$rows[$i]['id']] = true;
				
				++$i;
			}
		}

		if ($checkStoredRelatives)
		{
			foreach ($relatives as $relative)
			{
				$relId = $relative->id;
				
				// If this relative is stored and is set to be removed
				if ( isset($relationArray['removedStoredObjects'][$relId]) )
				{
					if ($returnOnlyTrue)
						unset($output[$relId]);
					else
						$output[$relId] = false;
				}
				else
				{
					// If this relative is only found in the database but it is marked for
					// removal
					if ( isset($relationArray['removedObjects'][$relId]) )
					{
						if ($returnOnlyTrue)
							unset($output[$relId]);
						else
							$output[$relId] = false;
					}
					else
					{
						// If we have a new object with the relative's id
						if ( isset($relationArray['newObjects'][$relId]) )
							$output[$relId] = true;
						else
						{
							if ( !isset($this->id) && !$returnOnlyTrue )
								$output[$relId] = false;
						}
					}
				}
			}
		}
		
        return $output;
    }
	
	/* A magical php function to call non-existant functions
	 * This function is used to call functions for working with specific relatives
	 * This function allows you to call the setR function for a relation by calling
	 * a fake function setRelationName for example
	 * Ex. User has relative Messages: $user->getMessages();
	 */
	public function __call($funcName, $args)
	{
		// Search for the function that they are calling
		if ( preg_match('/^getNew/', $funcName) )
		{
			$internalFunc = 'getNewRelatives';
			$prefix = '/^getNew/';
		}
		elseif ( preg_match('/^get/', $funcName) )
		{
			$internalFunc = 'getRelatives';
			$prefix = '/^get/';
		}
		elseif ( preg_match('/^unsetStored/', $funcName) )
		{
			$internalFunc = 'unsetStoredRelatives';
			$prefix = '/^unsetStored/';
		}
		elseif ( preg_match('/^set/', $funcName) )
		{
			$internalFunc = 'setRelatives';
			$prefix = '/^set/';
		}
		elseif ( preg_match('/^remove/', $funcName) )
		{
			$internalFunc = 'removeRelatives';
			$prefix = '/^remove/';
		}
		elseif ( preg_match('/^has/', $funcName) )
		{
			$internalFunc = 'hasRelatives';
			$prefix = '/^has/';
		}
		else
		{
			trigger_error('Call to undefined method Wallop::'.$funcName, E_USER_ERROR);
		}
			
		// Now ensure the relation they are trying to handle actually exists
		$relationName = preg_replace($prefix, '', $funcName);
		$relationInfo = $this->findRelation($relationName);
		if ( !$relationInfo )
		{
			trigger_error('Call to undefined method Wallop::'.$funcName.' (the relation '.$relationName
			             .' does not exist for this object)', E_USER_ERROR);
		}
		$relation =& $relationInfo[1];
		
		// Time to call the function, don't worry about their arguments just call the function and if it
		// fails they will be given a PHP error
		$numArgs = count($args);
		switch ($numArgs)
		{
			case 0:
				return $this->$internalFunc($relationName);
			
			case 1:
				return $this->$internalFunc($relationName, $args[0]);
			
			case 2:
				return $this->$internalFunc($relationName, $args[0], $args[1]);
				
			case 3:
				return $this->$internalFunc($relationName, $args[0], $args[1], $args[2]);
				
			case 4:
				return $this->$internalFunc($relationName, $args[0], $args[1], $args[2], $args[3]);
				
			case 5:
				return $this->$internalFunc($relationName, $args[0], $args[1], $args[2], $args[3], $args[4]);
				
			case 6:
				return $this->$internalFunc($relationName, $args[0], $args[1], $args[2], $args[3], $args[4], 
				                                            $args[5]);
			
			default:
				return $this->$internalFunc($relationName, $args[0], $args[1], $args[2], $args[3], $args[4], 
				                                            $args[5], $args[6]);
		}
		
		// Never reached anyways...
		return true;
	}
    // ============================================================================================
	// End of functions related to relations
	
	

    // Static functions mostly used for working internally with an object
	// ============================================================================================   

    /* Returns the array of errors set by static functions
     */
    public static function getStaticErrors()
    {
        $temp = self::$staticErrors;
        self::$staticErrors = array();

        return $temp;
    }

    /* Takes an object to remove
     * This function is just the internal recursive remove function for handling composite
     * relatives
     */
    private static function removeRecursive(Wallop &$object, $id = 0, $keepRelatives = false)
    {   
		if ( isset($object->id) )
        {
            $objectId = $object->id;
        }
        else
        {
            $objectId = $id;
			
			// We'll need the object id for the getRelatives call
			// if the keepRelatives flag is set
			if ($keepRelatives)
				$object->id = $id;
        }

		global $database;
        foreach($object->aggregates as $aggrRelTableName => &$aggregate)
        {
			// If the flag to keep relatives is set then get all aggregates
			// and store as newObjects
			if ($keepRelatives)
			{
				if ( isset($aggregate['modifiedObjects']) )
				{
					// Create an inList so we don't query what we don't have to
					// then move the modifiedObject id to newObject
					$inList = '(';
					foreach ($aggregate['modifiedObjects'] as $aggrId => $unused)
					{
						$inList .= $aggrId.',';
						
						$aggregate['newObjects'][$aggrId] = true;
					}
					$inList = trim($inList, ',').')';
				}
			
				$objs = $object->getRelatives($aggrRelTableName, 0, -1, '', '', 
				                              isset($inList) ? 'other.`id` NOT IN '.$inList : '');
				$i = 0;
				$numObjs = count($objs);
				while ($i != $numObjs)
				{
					$aggregate['objects'][$objs[$i]->id] = $objs[$i];
					$aggregate['newObjects'][$objs[$i]->id] = true;
					
					++$i;
				}
			}
			else
			{
				unset($aggregate['objects']);
				unset($aggregate['modifiedObjects']);
				unset($aggregate['newObjects']);
				unset($aggregate['removedStoredObjects']);
				unset($aggregate['uninitializedNewObjects']);
			}
			
			
			unset($aggregate['modifiedObjects']);
		
			// Generate this column
			$thisColumn = $object->generateThisColumn($aggrRelTableName);
			if ( !$thisColumn )
			{
				end($object->errors);
				self::$staticErrors[] = $object->errors[key($object->errors)];
				return false;
			}
		
			// Delete the records on the relation table
            $query = 'DELETE FROM `'.$aggrRelTableName.'` WHERE `'.$thisColumn.'` = ?';
            $result = $database->execQuery($query, $objectId);
			
			if ( !$result )
			{
				$staticError  = 'Failed to delete the records on the relation table ';
				$staticError .= '`'.$aggrRelTableName.'`. ';
				$staticError .= 'This was likely due to a mismatch between the database structure and the ';
				$staticError .= 'columns and/or table name you specified in the constructor of this object!';
				self::$staticErrors[] = $staticError;
				return false;
			}
        }
		
        foreach ($object->composites as $compRelTableName => &$composite)
        {
			$compositeData = $object->findRelationData($compRelTableName);
			$compObj = new $compositeData['className']();
		
			// Generate the relation columns
			$columnNames = $object->generateRelationColumns($compRelTableName, $compObj);
			if ( !$columnNames )
			{
				end($object->errors);
				self::$staticErrors[] = $object->errors[key($object->errors)];
				return false;
			}
			
            // Now that we have retrieved the ids of our composites
			$compIds = 
				self::hasNoCompositeRelatives($compObj, $columnNames['relative'],
											  $compRelTableName,
											  $objectId, $columnNames['this']);
										
			// Just return false, the public function using this function will use the error created
			// by hasNoCompositeRelatives() to explain what happened
			if ( !$compIds && !is_array($compIds) )
			{
				return false;
			}
											  
			// If the flag to keep relatives is set then get all aggregates
			// and store as newObjects
			if ($keepRelatives)
			{
				if ( isset($composite['modifiedObjects']) )
				{
					// Create an inList so we don't query what we don't have to
					// then move the modifiedObject id to newObject
					$inList = '(';
					foreach ($composite['modifiedObjects'] as $modId => $unused)
					{
						if ( !isset($compIds[$modId]) )
						{
							$inList .= $modId.',';
						
							$composite['newObjects'][$modId] = true;
						}
					}
					foreach ($compIds as $compId => $unused)
					{
						$inList .= $compId.',';
					}
					$inList = trim($inList, ',').')';
				}
			
				$objs = $object->getRelatives($compRelTableName, 0, -1, '', '', 
				                              isset($inList) ? 'other.`id` NOT IN '.$inList : '');
				$i = 0;
				$numObjs = count($objs);
				while ($i != $numObjs)
				{
					$composite['objects'][$objs[$i]->id] = $objs[$i];
					$composite['newObjects'][$objs[$i]->id] = true;
					
					++$i;
				}
			}
			else
			{
				unset($composite['objects']);
				unset($composite['newObjects']);
				unset($composite['removedStoredObjects']);
				unset($composite['uninitializedNewObjects']);
			}
			unset($composite['modifiedObjects']);
											  
			// remove all relations for this composite before recursing
            $query  = 'DELETE FROM `'.$compRelTableName.'` ';
			$query .= 'WHERE `'.$columnNames['this'].'`='.$objectId;
            $result = $database->execQuery($query);
			
			if ( !$result )
			{
				$staticError  = 'Failed to delete the records on the relation table ';
				$staticError .= '`'.$compRelTableName.'`. ';
				$staticError .= 'This was likely due to a mismatch between the database structure and the ';
				$staticerror .= 'columns and/or table name you specified in the constructor of this object!';
				self::$staticErrors[] = $staticError;
				return false;
			}
			
			$i = 0;
			$size = count($compIds);
			while ($i != $size)
			{
				// Try to recursively remove composites
				if ( !self::removeRecursive($compObj, $compIds[$i], false) )
					return false;
					
				++$i;
			}
        } // End of the composite foreach loop
		
		unset($object->id);
		unset($object->objectInfo['toBeRemoved']);
		unset($object->objectInfo['valueChanged']);

        $query = 'DELETE FROM `'.$object->objectInfo['tableName'].'` WHERE `id` = ? LIMIT 1';
        $result = $database->execQuery($query, $objectId);

		if ( !$result )
		{
			$staticError  = 'Failed to delete the record of composite `'.$object->objectInfo['tableName'].' ';
			$staticError .= 'This was likely due to a mismatch between the database structure and the ';
			$staticError .= 'columns and/or table name you specified in the constructor of this object!';
			self::$staticErrors[] = $staticError;
			return false;
		}
		
        return true;
    }
	
	/* Takes a template object of a composite ($compObj), the name of the relation table between the 
	 * composite and the object to commit ($thisRelationTable), the id of this object ($thisId), the 
	 * column name for this object on the relation table ($thisColumn), and the column for the 
	 * composite on this relation table ($compColumn)
	 * The extra parameter ($compIds) is used to specify a subset of composite ids to select
	 *
	 * Returns an array of ids to composites that have no other composite relations to them
	 */
	private static function hasNoCompositeRelatives(Wallop $compObj, $compColumn, 
	                                                $thisRelationTable, $thisId, $thisColumn, 
													array $compIds = null)
	{
		global $database;
		
		// Initialize the list of ids and the inList
		$ids = array();
		$inList = '(';
		if ( isset($compIds) )
		{
			if ( empty($compIds) )
				return array();
				
			foreach ($compIds as $compId)
			{
				$ids[$compId] = true;
				$inList .= $compId.',';
			}
			$inList = trim($inList, ',').')';
		}
		else
		{
			// If compIds is not set then query from the database the ids
			$query  = "SELECT `{$compColumn}` FROM `{$thisRelationTable}` ";
			$query .= "WHERE `{$thisColumn}` = {$thisId}";
			$result = $database->execQuery($query);
			
			if ( !$result )
			{
				$staticError  = 'Failed to retrieve records of this composite (`'.$thisRelationTable.'`) ';
				$staticError .= 'This was likely due to a mismatch between the database structure and the ';
				$staticError .= 'columns and/or table name you specified in the constructor of this object!';
				self::$staticErrors[] = $staticError;
				return false;
			}
			
			$rows = $database->getAllRows();

			// Returned an empty result set
			// Just finish the function and return an empty array
			if (!$rows || empty($rows) )
			{
				return array();
			}
		
			$i = 0;
			$numRows = count($rows);
			while ($i != $numRows)
			{
				$row = $rows[$i];
				
				$ids[$row[$compColumn]] = true;
				$inList .= $row[$compColumn].',';
				
				++$i;
			}
			$inList = trim($inList, ',').')';
			unset($rows);
		}
			
		// Foreach relation of this composite remove keys from the master array for composites that 
		// only have any other composite relations to them
		$compRelationTypes = array(&$compObj->aggregates, &$compObj->composites);
		foreach ($compRelationTypes as $compRelationType)
		{
			foreach ($compRelationType as $compRelationTableName => $compRelation)
			{
				$compRelationData = $compObj->findRelationData($compRelationTableName);
				// If this relation has the composite also as a composite relation
				$compRelationObj = new $compRelationData['className']();
				
				if ( isset($compRelationObj->composites[$thisRelationTable]) )
				{
					// Generate relation columns
					$relationColumns = $compRelationObj->generateRelationColumns($compRelationTableName, 
																				 $compObj);
					if ( !$relationColumns )
					{
						end($compRelationObj->errors);
						self::$staticErrors[] = $compRelationObj->errors[key($compRelationObj->errors)];
						return false;
					}
						
					$compRelationColumn = $relationColumns['this'];
					$secondCompColumn = $relationColumns['relative'];
				
					$query  = "SELECT `{$secondCompColumn}` ";
					$query .= "FROM `{$compRelationTableName}` ";
					$query .= "WHERE `{$secondCompColumn}` IN {$inList}";
					// Add a claus to not
					if ($thisRelationTable == $compRelationTableName)
						$query .= " AND `{$compRelationColumn}` != {$thisId}";

					$result = $database->execQuery($query);
					
					if ( !$result )
					{
						$staticError  = 'Failed to retrieve records of this composite (`';
						$staticError .= $thisRelationTable.'`) This was likely due to a mismatch between ';
						$staticError .= 'the database structure and the columns and/or table name you ';
						$staticError .= 'specified in the constructor of this object!';
						self::$staticErrors[] = $staticError;
						return false;
					}
					
					$rows = $database->getAllRows();
					
					$rowId = 0;
					$numRows = count($rows);
					while ($rowId != $numRows)
					{
						$id = $rows[$rowId][$secondCompColumn];
						
						unset($ids[$id]);
						
						$inList = str_replace_once(','.$id.',', ',', $inList, $found);
						if (!$found)
						{
							unset($found);
							$inList = str_replace_once('('.$id.',', '(', $inList, $found);
							
							if (!$found)
							{
								$inList = str_replace_once(','.$id.')', ')', $inList);
							}
						}
						
						++$rowId;
					}
				}
			}
		}
		
		$outputArray = array();
		foreach ($ids as $id => $unused)
		{
			$outputArray[] = $id;
		}
		
		return $outputArray;
	}
	
    /* Returns an $amount number of $tableName objects in the $sort order on the column $sortBy with 
	 * $conditions
     */
    public static function getMany($className, $start = 0, $amount = -1, $sortBy = '',
	                                                  $sort = 'ASC', $conditions = '')
    {
        if ( $className == '' || !is_string($className) )
        {
            $staticError = 'getMany(): must take at least the name of the class to get records for!';
			self::$staticErrors[] = $staticError;
            return false;
        }

		// Ensure the className exists and it inherits Wallop
		if ( !class_exists($className) || !is_subclass_of($className, 'Wallop') )
		{
			$staticError  = 'getMany(): class `'.$className.'` does not exist or does not inherit Wallop! ';
			$staticError .= 'Cannot retrieve the objects from the database...';
			self::$staticErrors[] = $staticError;
			return false;
		}
		
        // Instantiate the type of object to get records for
        $objectTemplate = new $className();
		if ( !$objectTemplate->constructTemplate() )
        {
			$staticError  = 'getMany(): Could not construct a template of \''.$className.'\'! ';
			$staticError .= 'The following is the error from the object:';
			self::$staticErrors[] = $staticError;
			self::$staticErrors[] = $objectTemplate->errors[0];
            return false;
        }
		if ( !empty($objectTemplate->errors) )
		{
			$staticError  = 'getMany(): Cannot continue with object \''.$className.'\', ';
			$staticError .= 'the object does not construct without error, check it\'s configuration! ';
			$staticError .= 'The following error is the object\'s error:';
			self::$staticErrors[] = $staticError;
			self::$staticErrors[] = $objectTemplate->errors[0];
			return false;
		}
		
        $tableName = $objectTemplate->objectInfo['tableName'];
        $columns = $objectTemplate->objectInfo['templateColumns'];

        // Setup our existing parameters for use below
		
		// Validate $start
		if ( !is_numeric($start) )
		{
			self::$staticErrors[] = 'getMany(): $start must be a numeric type!';
			return false;
		}
		$start = intval($start);
        if ($start < 0)
        {
			self::$staticErrors[] = 'getMany(): You cannot give a $start value that is less than 0!';
			return false;
		}
		
		// Validate $amount
		if ( !is_numeric($amount) )
		{
			self::$staticErrors[] = 'getMany(): $amount must be a numeric type!';
			return false;
		}
        $amount = intval($amount);
		if ($amount < -1)
		{
			self::$staticErrors[] = 'getMany(); $amount cannot be less than -1!';
		}
        if ($amount == -1)
            $amount = '18446744073709551615';
		
		// Validate $sortBy and $sort
		if ($sortBy != '')
		{
			if ( !is_string($sortBy) || !in_array($sortBy, $columns))
			{
				self::$staticErrors[] = 'getMany(): $sortBy must a string and must specify a column!';
				return false;
			}
			// Make sure $sortBy is a column in the relatives table
			if ( strtoupper($sort) != 'ASC' && strtoupper($sort) != 'DESC')
			{
				self::$staticErrors[] = 'getMany(): $sort must either be \'ASC\' or \'DESC\'!';
				return false;
			}
		}
		
		//  Validate $conditions
		if ( !is_string($conditions) )
		{
			self::$staticErrors[] = 'getMany(): $conditions must be a sting!';
		}
        // End of validation
        // We now assume all input is valid

        global $database;
        $query = 'SELECT `id`,'; 
		$i = 0;
		$numColumns = count($columns);
		while ($i != $numColumns)
		{
			$query .= '`'.$columns[$i].'`,';
			++$i;
		}
		$query = trim($query, ',');
		
		$query .= ' FROM `'.$tableName.'` ';
        
        if ($conditions != '')
            $query .= ' WHERE '.$conditions.' ';

        if ($sortBy != '')
            $query .= ' ORDER BY '.$sortBy.' '.$sort.' ';

        $query .= ' LIMIT '.$start.', '.$amount.' ';

        $result = $database->execQuery($query);
        if (!$result)
        {
            $staticError  = 'getMany(): The database query failed because: ';
			$staticError .= $database->error.' ';
			$staticError .= 'This was likely due to a mismatch between the database structure and the ';
			$staticError .= 'columns and/or table name you specified in the constructor of this object!';
			self::$staticErrors[] = $staticError;
            return false;
        }

        $rows = $database->getAllRows();
        if (!$rows)
        {
			// There might just be no rows returned
			if ( empty($rows) )
				return array();
		
            self::$staticErrors[] = 'getMany(): Failed to get the records from the database!';
            return false;
        }

        $objects = array();
		$i = 0;
		$numRows = count($rows);
        while ($i != $numRows)
        {
			$row = $rows[$i];
				
            foreach($columns as $column)
            {
                $objectTemplate->id = $row['id'];
                $objectTemplate->record[$column] = $row[$column];
            }

            $objects[] = clone $objectTemplate;
			
			++$i;
        }

        return $objects;
    }
	
	/* The final magic PHP functions, as you may guess from it's name it is used to call non-existent
	 * static functions. So we can use this to let the user call getMany() but with the className as
	 * part of the function's name
	 * Ex. Class Users: Wallop::getManyUsers();
	 */
	public static function __callStatic($funcName, $args)
	{
		// Search for the function that they are calling
		if ( preg_match('/^getMany/', $funcName) )
		{
			$internalFunc = 'getMany';
			$prefix = '/^getMany/';
		}
		else
		{
			trigger_error('Call to undefined method Wallop::'.$funcName, E_USER_ERROR);
		}
			
		// Now ensure the relation they are trying to handle actually exists
		$className = preg_replace($prefix, '', $funcName);
		
		if ( !class_exists($className) || !is_subclass_of($className, 'Wallop') )
		{
			trigger_error('Call to undefined method Wallop::'.$funcName.' (the class `'.$className
			             .'` does not exist)', E_USER_ERROR);
		}
		
		// Time to call the function, don't worry about their arguments just call the function and if it
		// fails they will be given a PHP error
		$numArgs = count($args);
		switch ($numArgs)
		{
			case 0:
				return self::$internalFunc($className);
			
			case 1:
				return self::$internalFunc($className, $args[0]);
			
			case 2:
				return self::$internalFunc($className, $args[0], $args[1]);
				
			case 3:
				return self::$internalFunc($className, $args[0], $args[1]. $args[2]);
				
			case 4:
				return self::$internalFunc($className, $args[0], $args[1]. $args[2], $args[3]);
				
			default:
				return self::$internalFunc($className, $args[0], $args[1]. $args[2], $args[3], $args[4]);
		}
		
		// Never reached anyways...
		return true;
	}
	// ============================================================================================
	// End of static functions


	
	// Functions for helping to install your database
	// ============================================================================================
	
	// These functions will require that the database user provided has the permissions to 
	// modify tables
	
	/* This function installs the relational tables in the database for this object
	 */
    public function createRelational()
    {
        global $database;
        $relationTypes = array();
        
        if(count($this->aggregates) > 0)
        {
            $relationTypes[] = &$this->aggregates;
        }
        if(count($this->composites) > 0)
        {
            $relationTypes[] = &$this->composites;
        }
		
		// First create the primary key for this object
		if ( !$this->createIdColumn($this->objectInfo['tableName']) )
		{
			$error  = 'createRelational(): Failed to create primary key for table \'';
			$error .= $this->objectInfo['tableName'].'\'!';
			$this->errors[] = $error;
			return false;
		}	
		
		foreach ($relationTypes as $relationType)
		{
			foreach ($relationType as $relationTableName => $relation)
			{
				$relationData = $this->findRelationData($relationTableName);
				$relationTemplate = new $relationData['className']();
				if ( !empty($relationTemplate->errors) )
				{
					$error  = 'createRelational(): Cannot continue with relation ';
					$error .= '\''.$relationTableName.'\' the relation does not construct without error, ';
					$error .= 'check it\'s configuration! The following error is the relation\'s error:';
					$this->errors[] = $error;
					$this->errors[] = $relationTemplate->errors[0];
					return false;
				}
				
				// Generate Relation columns
				$relationColumns = $this->generateRelationColumns($relationTableName, $relationTemplate);
				if ( !$relationColumns )
				{
					end($this->errors);
					$this->errors[key($this->errors)] 
						= 'createRelational(): '.$this->errors[key($this->errors)];
					return false;
				}

				$query  = 'CREATE TABLE IF NOT EXISTS `'.$relationTableName.'` ( ';
				$query .= $relationColumns['this'].' INT(10) unsigned NOT NULL, ';
				$query .= $relationColumns['relative'].' INT(10) unsigned NOT NULL, ';
				$query .= 'PRIMARY KEY(`'.$relationColumns['this'].'`,`'.$relationColumns['relative'].'`) ';
				$query .= ')';

				$database->execQuery($query);
			}
		}
		
		return true;
    }

	/* This function creates the primary key (id) for this object
	 */
    private function createIdColumn($table)
    {
		if ( !is_string($table) )
		{
			$this->errors[] = 'createIdColumn(): $table must be a string!';
			return false;
		}
	
        global $database;
        if ( !$database->execQuery('SHOW COLUMNS FROM `'.$table.'` WHERE `field` = \'id\'') )
		{
			$error  = 'createIdColumn(): It appears the table `'.$table.'` does not exist, ';
			$error .= 'please check what values you passed into the constructor, and enusre that ';
			$error .= 'you have already setup the table (without a primary key) before calling ';
			$error .= 'createRelational().';
			$this->errors[] = $error;
			return false;
		}
		
        $row = $database->getRow();
        if( !$row )
        {
			$query = 'ALTER TABLE `'.$table.'` DROP PRIMARY KEY';
            $database->execQuery($query);
			
			$query = 'ALTER TABLE `'.$table.'` ADD `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT FIRST';
            $database->execQuery($query);
        }
		
		return true;
    }
	// ============================================================================================
	// End of database installation functions
	
	
	// Misc functions for helping other functions do some generic operation
	// ============================================================================================
	
	/* Returns the relationTableName given a relationName (an alias or relationTableName)
	 * This function basically just converts aliases to their corresponding relationTableName
	 */
	private function findRelationTableName($relationName)
	{
		if ( isset(self::$classInfo[$this->objectInfo['tableName']]['aliasMap'][$relationName]) )
			return self::$classInfo[$this->objectInfo['tableName']]['aliasMap'][$relationName];
		else
			return $relationName;
	}
	
	/* Returns an array that contains the column name for this object and the relative for a given relation
	 */
	private function generateRelationColumns($relationName, Wallop $relationTemplate)
	{
		$output = array();
		
		$output['this'] = $this->generateThisColumn($relationName);
		if ( !$output['this'] )
		{
			return false;
		}
		
		$output['relative'] = $this->generateRelativeColumn($relationName, $relationTemplate);
		if ( !$output['relative'] )
		{
			return false;
		}
		
		// Returns array( ['this'] => 'this column name', ['relative'] => 'relative's column name' )
		return $output;
	}
	
	private function generateThisColumn($relationName)
	{
		$relationData = $this->findRelation($relationName);
		$relation = $relationData[0];
	
		if ( isset($relation['relationColumnName']) )
			return $relation['relationColumnName'];
		else if ( isset(self::$classInfo[$this->objectInfo['tableName']]['defaultColumnName']) )
			return self::$classInfo[$this->objectInfo['tableName']]['defaultColumnName'];
		else
		{
			$error  = 'Cannot get this table column of this relation (`'.$relationName.'`). You must at ';
			$error .= 'least specify the relationColumnName for the relation or the defaultColumnName ';
			$error .= 'for this object!';
			$this->errors[] = $error;
			return false;
		}
	}
	
	private function generateRelativeColumn($relationName, Wallop $relationTemplate)
	{
		$relationData = $this->findRelation($relationName);
		$relation = $relationData[0];
	
		// if the relative is the same type
		if ( $relationTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
		{
			if ( !isset($relation['otherRelationColumnName']) )
			{
				$error  = 'Cannot get the relative table column of this relation ';
				$error .= '(`'.$relationName.'`). For relations between this object and itself ';
				$error .= 'you must specify the \'otherRelationColumnName\' for this relation!';
				$this->errors[] = $error;
				return false;
			}
	
			return $relation['otherRelationColumnName'];
		}
		else
		{
			if ( $relation )
			{
				if ( isset($relation['relationColumnName']) )
				{
					return $relation['relationColumnName'];
				}
				else
				{
					$relativeInfo = self::$classInfo[$relationTemplate->objectInfo['tableName']];
					if ( isset($relativeInfo['defaultColumnName']) )
					{
						return $relativeInfo['defaultColumnName'];
					}
				}
			}
				
			// If any of the other code paths do not return then it's an error
			$error  = 'Cannot get the relative table column of this relation ';
			$error .= '(`'.$relationName.'`). The relative of this object did not specify ';
			$error .= 'a relation column name for this relation (maybe the relation to this object ';
			$error .= 'is not specified in the relative\'s configuration) and also did not specify ';
			$error .= 'a default column name!';
			$this->errors[] = $error;
			return false;
		}
	}
	
	/* Returns an array of pointers to the places in the relations arrays (either aggregates or composites
	 * where the relation indentified by $relationName is stored. The first of the array is the relation info
	 * in the static array and the second is the actually objects and maps (specific to this object)
	 */
	private function findRelation($relationName)
	{
		$relationName = $this->findRelationTableName($relationName);
		
        if ( isset(self::$classInfo[$this->objectInfo['tableName']]['aggregatesInfo'][$relationName]) )
		{
			return array(self::$classInfo[$this->objectInfo['tableName']]['aggregatesInfo'][$relationName],
			               &$this->aggregates[$relationName]);
		}
		else
		{
			if ( isset(self::$classInfo[$this->objectInfo['tableName']]['compositesInfo'][$relationName]) )
			{
				return array(self::$classInfo[$this->objectInfo['tableName']]['compositesInfo'][$relationName],
			                   &$this->composites[$relationName]);
			}
			else
			{
				$error  = 'Cannot handle relatives for relation \''.$relationName;
				$error .= '\' it does not exist in this object!';
				$this->errors[] = $error;
				return $false;
			}
		}
	}
	
	/* This function mimics the findRelation() function except that it only returns the static relation data
	 */
	private function findRelationData($relationName)
	{
		$relationName = $this->findRelationTableName($relationName);
		
        if ( isset(self::$classInfo[$this->objectInfo['tableName']]['aggregatesInfo'][$relationName]) )
		{
			return self::$classInfo[$this->objectInfo['tableName']]['aggregatesInfo'][$relationName];
		}
		else
		{
			if ( isset(self::$classInfo[$this->objectInfo['tableName']]['compositesInfo'][$relationName]) )
			{
				return self::$classInfo[$this->objectInfo['tableName']]['compositesInfo'][$relationName];
			}
			else
			{
				$error  = 'Cannot handle relatives for relation \''.$relationName;
				$error .= '\' it does not exist in this object!';
				$this->errors[] = $error;
				return false;
			}
		}
	}
	
	/* This function checks to see if $relation has any objects to handle
	 */
	private function relationHasObjectsToHandle(array $relation)
	{
		return 
			(isset($relation['objects'])                 && !empty($relation['objects']))                 ||
			(isset($relation['modifiedObjects'])         && !empty($relation['modifiedObjects']))         ||
			(isset($relation['newObjects'])              && !empty($relation['newObjects']))              ||
			(isset($relation['removedObjects'])          && !empty($relation['removedObjects']))          ||
			(isset($relation['removedStoredObjects'])    && !empty($relation['removedStoredObjects']))    ||
			(isset($relation['uninitializedNewObjects']) && !empty($relation['uninitializedNewObjects'])) ||
			(isset($relation['originalObjects'])         && !empty($relation['originalObjects']));
	}
	// ============================================================================================
	// End of the misc functions
}


/* Does a simple string replace once
 * $found is set to true if the string is found
 * Credit to nick on php.net: http://www.php.net/manual/en/function.str-replace.php#86177
 */
function str_replace_once($search, $replace, $subject, &$found = null)
{
    $firstChar = strpos($subject, $search);
    if($firstChar !== false)
	{
        $beforeStr = substr($subject,0,$firstChar);
        $afterStr = substr($subject, $firstChar + strlen($search));
		$found = true;
        return $beforeStr.$replace.$afterStr;
    } 
	else 
	{
		$found = false;
        return $subject;
    }
}
?>
