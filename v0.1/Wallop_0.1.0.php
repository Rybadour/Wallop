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

$database = new Database();
$database->connect();



/* Wallop v0.1
 * In this version the system is highly simplified.
 * It lacks features that provide additional flexibility in the design of
 * your object. These features also lead to effeciency.
 */
abstract class Wallop
{
	// Class members
    private $id;
    private $objectInfo = array();
    private $aggregates = array();
    private $composites = array();
    private $columns    = array();
    private $record     = array();
    private $errors     = array();
	
	
	// Functions related to intialization and getting object info
	// ==========================================================================================
   
    /* If $id has a value then try to load the record from the database
     * Store the record in $record as an associative array
	 *
     * $objectInfo: Array of values that tell info about the object
     *   -> 'tableName' (string): the table name for this object in the database
     *   -> 'className' (string): the class name for this object as defined by
     *                            the developer, defaults to 'tableName' if unset
     *
     * $relations: Array of associative arrays detailing the relation between
     *             this class and other objects
     *   -> 'className' (string): the class name of the other object
     *   -> 'relationTableName' (string): the name of the relation table
     *   -> 'dependency' ('composite', 'aggregate'): whether the other object is necessary for
     *                                this object to exist
     */
    public function Wallop($objectInfo = null, $id = null, $relations = null)
    {
        // Validate parameters
        if ($objectInfo == null || !is_array($objectInfo) || ($objectInfo['tableName'] == '' && $objectInfo['className'] == '') )
        {
           $error = 'Constructor arg $objectInfo must be an array, with at least \'tableName\' or \'className\' specified.';
           $this->errors[] = $error;
           return;
        }

        // If $objectInfo['className'] was not specified, assume it to be the same as the tableName
        if ($objectInfo['className'] == '')
            $objectInfo['className'] = $objectInfo['tableName'];
		
		// OR
		
		// If the tableName isn't specified then assume it is the same as the className
		if ($objectInfo['tableName'] == '')
			$objectInfo['tableName'] = $objectInfo['className'];

        // If this this Wallop is related to other Wallops

        if ( $relations != null && !is_array($relations) )
        {
            $this->errors[] = 'Constructor arg $relations must be null or an array.';
            return;
        }

        if ($relations != null)
        {
            foreach ( $relations as &$relation )
            {
                if ( !is_array($relation) )
                {
                    $this->errors[] = 'Constructor arg $relations must be an array of arrays.';
                    return false;
                }
                
                if ( $relation['className'] == '' || $relation['relationTableName'] == '')
                {
                    $error = 'Constructor arg $relations must have arrays that all';
                    $error .= ' contain \'className\' and \'tableName\'.';
                    $this->errors[] = $error;
                    return;
                }

                if ( !isset($relation['dependency']) || $relation['dependency'] == '' )
                     $relation['dependency'] = 'aggregate';

                if ( $relation['dependency'] != 'aggregate' &&
                     $relation['dependency'] != 'composite' )
                {
                    $error = 'Constructor arg $relation must have \'dependency\' ';
                    $error .= 'set to \'aggregate\' or \'composite\'!';
                    $this->errors[] = $error;
                    return;
                }
            }

            // Only build it after all relations are checked
            foreach ($relations as &$relation)
            {
                if ($relation['dependency'] == 'aggregate')
                {
                    $this->aggregates[$relation['className']]['relationTableName'] = $relation['relationTableName'];
                }
                else
                {
                    $this->composites[$relation['className']]['relationTableName'] = $relation['relationTableName'];
                }
            }
        }

        // At this point we assume all data is set properly
        $this->objectInfo = $objectInfo;
        
        if ($id != null)
            $this->initialize($id);
    }

    /* Setups up the list of columns for the Wallop object.
     * This function is only called when the object needs
     * the thing this function sets up.
     */
    protected function constructTemplate()
    {
        // Don't do anything if the object is already setup
        if ( count($this->columns) != 0 )
            return true;

        global $database;
        $result = $database->execQuery('SHOW COLUMNS FROM `'. $this->objectInfo['tableName'].'`');
        
        // If the table doesn't exist

        if ( !$result )
        {  
            $this->errors[] = 'Table \''.$this->objectInfo['tableName'] .'\' doesn\'t exist.';
            return false;
        }

        $rows = $database->getAllRows();

        if ( !$rows )
        {
            // Is this error accurate? o.0
            $this->errors[] = 'Cannot get columns for table \''. $this->objectInfo['tableName'] .'\' !';
            return false;
        }

        $this->columns = array();
        
        foreach ($rows as $row)
        {
            if ($row['Field'] == 'id')
                continue;

			// Setup the $columns class member
            $this->columns[] = $row['Field'];
            $this->record[$row['Field']] = '';
        }

        return true;
    }

    /* Returns false if no id is passed, and true if the intialization
     * is successful
     * Iniatilizes the object using the id to query the database
     * and fill the object
     */
    public function initialize($id = null)
    {
        if ($id == null)
        {
            $this->errors[] = 'Cannot intialize, $id is null!';
            return false;
        }

		// The template of this object must be setup first
        $this->constructTemplate();

        global $database;

        // Setup the select field
        $columns = '';
        foreach ($this->columns as $column)
            $columns .= '`'. $column .'`,';
        $columns = trim($columns, ',');

        $result = $database->execQuery('SELECT '.$columns.' FROM `'.$this->objectInfo['tableName'].'` WHERE `id`=? LIMIT 1', $id);
        if (!$result)
        {
            $this->errors[] = 'Unable to Query record from Table \''. $this->objectInfo['tableName'] .'\'. Table may not exist!';
            return false;
        }

        $row = $database->getRow();
        if ( !$row )
        {
            $this->errors[] = 'No record of the \''.$this->objectInfo['tableName'].'\' table has an id of \''.$id.'\'!';
            return false;
        }

        $this->id = $id;
        foreach ($this->columns as $column)
            $this->record[$column] = $row[$column];

        return true;
    }
	
	/* Returns the id of the object. 
     * This is automatically considered the table's primary key.
     */
    public function getId()
    {
        return $this->id;
    }

    /* Returns the objects information array
     */
    public function getObjectInfo()
    {
        return $this->objectInfo;
    }

    /* Returns the array of column names
     */
    public function getColumns()
    {
        $this->constructTemplate();

        return $this->columns;
    }
    // ==========================================================================================
	// End of iniatlization and object info function
	
	

	// Functions related to working with this object and it's attributes
	// ==========================================================================================
	
    /* Takes a column name
     * Returns the value of the column in the record if it exists
     * otherwise it sets the errors array and returns false
     */
    public function get($column)
    {
        $this->constructTemplate();

        // Check if column even exists
        if ( !in_array($column, $this->columns) )
        {
            $this->errors[] = 'Cannot get value \''.$column.'\', that column does not exist in this class!';
            return false;
        }

        // Return the value of the column passed in
        if(isset($this->record[$column]))
            return $this->record[$column];
        else
            return false;
    }
    
    /* Takes a column name and a value
     * Sets that column with that value if the column exists
     * then Returns true
     * otherwise it sets errors and returns false
     */
    public function set($column, $value)
    {
        $this->constructTemplate();

        // Check if column even exists
        if ( !in_array($column, $this->columns) ) 
        {
            $this->errors[] = 'Cannot set value \''.$column.'\', that column does not exist in this class!';
            return false;
        }

        if ($this->record[$column] != $value)
        {
            $this->objectInfo['valueChanged'] = true;

            // Set the column to the specified value
            $this->record[$column] = $value;
        }

        return true;
    }

    /* Updates the current record of the Wallop to the database,
     * or adds it if the id of the Wallop is not set (and thus
     * has not been pulled from the database, meaning the Wallop
     * has yet to be stored)
     * If the object is flagged for removal then it is removed
     * along with any composites that are only referenced by this
     * object.
     */
    public function commit($commitRelatives = true)
    {
        global $database;

        $this->constructTemplate();

        // Don't want any nasty foreach failures
        $values = array();

        // Since the array of values to set is going to
        // be identical for both cases, we may as well
        // assemble it beforehand. Let's do that.

        foreach ($this->columns as $column)
            $values[] = $this->record[$column];

        // If it exists in the database,
        // build a query to update it.
        if ( isset($this->id) )
        {
            if ( isset($this->objectInfo['toBeRemoved']) && $this->objectInfo['toBeRemoved'] )
            {
                // Removes the record from the database if it is flagged to be removed
                // Calls remove on composites
                //return self::removeRecursive(new $this->objectInfo['className'](), $this->id);
                return self::removeRecursive($this);
            }

            // Only update the database if some value was change in the set function
            if ( isset($this->objectInfo['valueChanged']) && $this->objectInfo['valueChanged'] )
            {
                $query = 'UPDATE `'.$this->objectInfo['tableName'].'`
                          SET ';

                foreach ($this->columns as $column)
                    $query .= '`'.$column.'` = ?,';
                $query = trim ($query, ',');

                $query .= ' WHERE `id` = ?';

                // In the case of an update, we have an extra value to
                // account for; the id - We specify 'WHERE `id` = ?`', so
                // now we have to append it to the end of $values if
                // we are updating (but not if we are inserting, since
                // then there is no specification of 'WHERE `id` = ?')

                $values[] = $this->id;

                $database->execQueryWith($query, $values);
            }
        }

        // If it does not exist in the database,
        // build a query to add it.
        else
        {
            $query = 'INSERT INTO `'.$this->objectInfo['tableName'].'`
                      (';

            foreach ($this->columns as $column)
                $query .= '`'.$column.'`,';
            $query = trim ($query, ',');

            $query .= ')
                       
                       VALUES (';

            foreach ($this->columns as $column)
                $query .= '?,';
            $query = trim ($query, ',');

            $query .= ')';

            $this->id = $database->execQueryWith($query, $values);
        }
		
		if ( isset($this->objectInfo['valueChanged']) )
			unset($this->objectInfo['valueChanged']);

        $relations = array_merge($this->aggregates, $this->composites);
        foreach ($relations as $relationClassName => $relation)
        {
            if( isset($relation['objects']) )
            {
                $relationTemplate = new $relationClassName();
                $relativeColumn = self::columnNameGenerator($relationTemplate->objectInfo['tableName']);

                // if the relative is the same type
                if ( $relationTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
                    $relativeColumn .= '2';

                $thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

                if ( isset($relation['removedObjects']) )
                {
                    // Search for the id of the objects to be removed
                    foreach ( array_keys($relation['removedObjects']) as $id )
                        foreach ($relation['objects'] as $pseudoId => $obj)
                            if ( $id == $obj->id )
                            {
                                // and remove the relation between this object and that relative
                                $query  = 'DELETE FROM `'.$relation['relationTableName'].'` ';
                                $query .= 'WHERE `'.$relativeColumn.'`='.$id.' && `'.$thisColumn.'`='.$this->id.' LIMIT 1';
                                $database->execQuery($query);

                                $removed[$pseudoId] = null;
                                --$relation['lastPseudoId'];

                                break;
                            }
                }

                // Only commit relatives if the flag to do so is set
                if ( $commitRelatives )
                {
                    foreach ($relation['objects'] as &$object)
                        $object->commit();
                }

                // MUST BE AFTER the object commits
                if ( isset($relation['newObjects']) )
                {
                    // Search for newObjects to add relations for in the database
                    foreach ( array_keys($relation['newObjects']) as $newId )
                    {
                        $newObject = $relation['objects'][$newId];

                        if ( !isset($newObject->id) )
                           continue; 

                        // and add a relation in the database for them
                        $query  = 'INSERT INTO `'.$relation['relationTableName'].'` ';
                        $query .= '(`'.$relativeColumn.'`,`'.$thisColumn.'`) ';
                        $query .= 'VALUES ('.$newObject->id.','.$this->id.')';
                        $database->execQuery($query);
                    }
                }

                if ( isset($removed) )
                {
                    foreach ( array_keys($relation['objects']) as $pseudoId )
                        if ( array_key_exists($pseudoId, $removed) )
                            unset($relation['objects'][$pseudoId]);
                }
            }
        }
    }

    /* Flags this object for removal from the database
     */
    public function remove()
    {
        if ( !isset($this->id) )
        {
            $this->errors[] = 'Cannot remove this object, it must be initialized first!';
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
        if ( !$this->objectInfo['toBeRemoved'] )
        {
            $this->errors[] = 'Cannot unremove this object, it is not already flagged for removal!';
            return false;
        }

        $this->objectInfo['toBeRemoved'] = false;

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
            unset($this->errors);
            $this->errors = array();
        }
        return $temp;
    }
	// ==========================================================================================
	// End of functions relating to modifying this object
	
	
	
		// Functions related to relations
	// ==========================================================================================
	
    /* Takes the class name of a related table
     * Sets the list of relatives in this object for the supplied relation
     * based on the $amount of relatives starting at $start
     */
    private function initializeRelation($relationName, $start = 0, $amount = -1)
    {
        if (!isset($this->id))
        {
            $this->errors[] = 'Cannot initialize relations when this object is not initialized!';
            return false;
        }

        if (!isset($relationName) || $relationName == '')
        {
            $this->errors[] = 'initializeAggregates() must take a non-null $aggregateName!';
            return false;
        }
       
        // Merged aggregates and composites for convienence
        $relatives = array_merge($this->aggregates, $this->composites);
        
        if (!isset($relatives[$relationName]))
        {
            $this->errors[] = 'Cannot initialize relation \''.$relationName.'\' it does not exist in this object!';
            return false;
        }

        //Assume the relationName is validated
        $relativeTemplate = new $relationName();
        if ( !$relativeTemplate->constructTemplate() )
        {
            $this->errors = array_merge($this->errors, $relativeTemplate->getErrors());
            $this->errors[] = 'Could not construct a template of \''.$relationName.'\'!';
            return false;
        }

        // The lower-camel cased column name of the relation table
        $relativeColumn = self::columnNameGenerator($relativeTemplate->objectInfo['tableName']);

        // if the relative is the same type
        if ( $relativeTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
           $relativeColumn .= '2'; 

        $thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

        $start = intval($start);
        if ($start < 0)
            $start = 0;
        $amount = intval($amount);
        if ($amount < 0)
            $amount = '18446744073709551615';
         

        $query = 'SELECT `id`,';
        foreach ($relativeTemplate->columns as $column)
            $query .= $column.',';
        $query = trim($query, ',');

        $query .= ' FROM `'.$relativeTemplate->objectInfo['tableName'].'` AS other,';
            $query .= '(SELECT `'.$relativeColumn.'` FROM ';
            $query .= '`'.$relatives[$relationName]['relationTableName'].'` ';
            $query .= 'WHERE `'.$thisColumn.'`='.$this->id.' LIMIT '.$start.','.$amount.') ';
            $query .= 'AS ids ';
        $query .= 'WHERE other.`id`=ids.`'.$relativeColumn.'`';


        global $database;
        $result = $database->execQuery($query);
        
        if (!$result)
        {
            $this->errors[] = 'Could not query the database to get relatives!';
            return false;
        }

        $rows = $database->getAllRows();
        if (!$rows)
        {
            $this->errors[] = 'Failed to get the records of the relatives from the database!';
            return false;
        }

        $relatives = array();
        ++$start; //So the relatives array is lied up with the database
        foreach ($rows as $row)
        {
            $relative = new $relationName();
            $relative->columns = $relativeTemplate->columns;
            foreach($relative->columns as $column)
            {
                $relative->id = $row['id'];
                $relative->record[$column] = $row[$column];
            }

            // store relatives by their index from the query
            $relatives[$start] = $relative;
            ++$start;
        }

      

        if ( isset($this->aggregates[$relationName]) )
        {
            $targetArray = &$this->aggregates;
        }
        else
        {
            if ( isset($this->composites[$relationName]) )
            {
                $targetArray = &$this->composites;
            }
            else
            {
                $error = 'WTF this shouldn\'t even happen!';
                $error .= 'Tell the maker of this thing right away!';
                $this->errors[] = $error;
                return false;
            }
        }

        if ( isset($targetArray[$relationName]['lastPseudoId']) )
        {
            $query  = 'SELECT count(`'.$relativeColumn.'`) AS count FROM `'.$relatives[$relationName]['relationTableName'].'` ';
            $query .= 'WHERE `'.$thisColumn.'`='.$this->id;

            $database->execQuery($query);
            $row = $database->getRow();

            $targetArray[$relationName]['lastPseudoId'] = $row['count'];
        }

        foreach ($relatives as $key => $relative)
        {
            $targetArray[$relationName]['objects'][$key] = $relative;
            //$this->targetArray[$relationName]['idMap'][$relative->id] = $key;
        }

        return true;
    }

	/* Returns an $amount of relatives of $relationName
     * starting from $start
     */
    public function getRelatives($relationName, $start = 0, $amount = -1)
    {
        if ( !$this->initializeRelation($relationName, $start, $amount) )
            return false;

        $start = intval($start);
        if ($start < 0)
            $start = 0;
        ++$start; // get $start lined up with the database
        $amount = intval($amount);
        if ($amount < 0)
            $amount = -1;
        
        $objects = array();

        if ( isset($this->aggregates[$relationName]) )
        {
            if ($amount == -1)
            {
                $arr = &$this->aggregates[$relationName]['objects'];
                end($arr);
                $end = key($arr) + 1;
            }
            else
                $end = $start + $amount;

            for ($i = $start; $i != $end; ++$i) 
                $objects[] = $this->aggregates[$relationName]['objects'][$i];

            return $objects;
        }
        else
        {
            if ( isset($this->composites[$relationName]) )
            {
                if ($amount == -1)
                {   
                    $arr = &$this->composites[$relationName]['objects'];
                    end($arr);
                    $end = key($arr) + 1;
                }
                else
                    $end = $start + $amount;

                for ($i = $start; $i != $end; ++$i) 
                    $objects[] = $this->composites[$relationName]['objects'][$i];

                return $objects;
            }
            else
            {
                $error = 'Cannot get relative of class \''.$relationName.'\' ';
                $error = 'it was not specified in the initialize of this object!';
                $this->errors[] = $error;
                return false;
            }
        }
    }
	
    /* setR ( wallopType, wallops )
     * Takes a type of Wallop and an array of Wallops of that type
     * Adds them to the appropriate array in this Wallop
     * (i.e. either $aggregates or $composites)
     */
    public function setRelatives($relationName, $relatives)
    {
        if ( !is_array($relatives) )
            $relatives = array($relatives);

        // Check to see if this is an aggregate or a composite
        // Does this type exist in $aggregates?
        if ( isset ($this->aggregates[$relationName]) )
        {
            // Yeah? Then we're using $aggregates
            $targetArray = &$this->aggregates;
        }
        
        // No? Well, does it exist in $composites?
        else if ( isset ($this->composites[$relationName]) )
        {
            // Yeah? Then we're using $composites
            $targetArray = &$this->composites;
        }

        // It wasn't in either one? Well, fuck - they gave a non-relative Wallop type
        if ( !isset ($targetArray) )
        {
            $this->errors[] = 'Tried to add Wallops of a non-relative type!';
            return false;
        }

        foreach ($relatives as $key => $relative)
        {
            // Make sure this Wallop is an instance of the Wallop type they specified.
            if ( !($relative instanceof $relationName) )
            {
                $this->errors[] = 'Tried to add a Wallop of the wrong type!';
                continue;
            }

            // We need to know the lastPseudoId 
            if ( !isset($targetArray[$relationName]['lastPseudoId']) )
            {
                global $database;

                $relativeColumn = self::columnNameGenerator($relative->objectInfo['tableName']);
                
                // if the relative is the same type
                if ( $relative->objectInfo['tableName'] == $this->objectInfo['tableName'] )
                    $relativeColumn .= 'Id';

                $thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

                $query  = 'SELECT count(`'.$relativeColumn.'`) AS count FROM `'.$targetArray[$relationName]['relationTableName'].'` ';
                $query .= 'WHERE `'.$thisColumn.'`='.$this->id;

                $database->execQuery($query);
                $row = $database->getRow();

                $targetArray[$relationName]['lastPseudoId'] = $row['count'] + 1;
            }

            // Do we already have a Wallop with this ID in here? Let's take a look...
			$match = false;
            if( isset($targetArray[$relationName]['objects']) )
            {       
                foreach ( $targetArray[$relationName]['objects'] as $id => $otherRelative )
                {
                    if ( isset($relative->id) && $otherRelative->id == $relative->id ) 
                    {
                        $match = true;
						$matchId = $id;
                        break;
                    }
                }
			}
			
			if($match)
			{
				$targetArray[$relationName]['objects'][$id] = $relative;

				if(!isset($targetArray[$relationName]['removedObjects']))
					$targetArray[$relationName]['removedObjects'] = array();

				// If this object was flagged for removal
				if ( array_key_exists($relative->id, $targetArray[$relationName]['removedObjects']) )
				{
					// then unremove it
					unset($targetArray[$relationName]['removedObjects'][$relative->id]);
				}
			}
			else
			{
				$nextId = $targetArray[$relationName]['lastPseudoId'];
				$targetArray[$relationName]['objects'][$nextId] = $relative;
				++$targetArray[$relationName]['lastPseudoId'];
				$targetArray[$relationName]['newObjects'][$nextId] = null;
			}            
        }
    }


    /* Removes relations based on the relation and array of objects provided
     */
    public function removeRelatives($relationName, $relatives)
    {
        $relationTemplate = new $relationName();
        if ( !$relationTemplate instanceof Wallop )
        {
            $this->errors[] = 'Cannot remove relation '.$relationName.' it is does not inherit from Wallop!';
            return false;
        }

        if ( !is_array($relatives) )
            $relatives = array($relatives);

        foreach($relatives as $relative)
        {
            $relId = $relative->id;
            if( isset($this->aggregates[$relationName]) )
            {
                $depend = 'aggregates';
            }
            else
            {
                if( isset($this->composites[$relationName]) )
                {
                    $depend = 'composites';
                }
                else
                {
                    $this->errors[] = 'Cannot remove relation '.$relationName.' it does not relate to this object!';
                    return false;
                }
            }

            $arrRef = &$this->$depend;

            global $database;
            $relationTableName = $arrRef[$relationName]['relationTableName'];

            $thisColumnName = self::columnNameGenerator($this->objectInfo['tableName']);
            $relativeColumnName = self::columnNameGenerator($relative->objectInfo['tableName']);

            // if the relative is the same type
            if ( $relative->objectInfo['tableName'] == $this->objectInfo['tableName'] )
                $relativeColumnName .= '2';

			$query  = 'SELECT * FROM `'.$relationTableName.'` ';
			$query .= 'WHERE `'.$thisColumnName.'` = ? AND `'.$relativeColumnName.'` = ?';
            $database->execQuery($query, $this->id, $relId);
            $row = $database->getRow();
            if(!$row)
            {
                $this->errors[] = 'Cannot flag object with id '.$relId.' is not related in database with this object!'; 
            }
            else
            {
                $arrRef[$relationName]['removedObjects'][$relId] = null;
            }
        }
    }

    /* Returns an array of id=>true/false
     * (if the object's id exists in the database)
     */
    public function hasRelatives($relationName, $relatives)
    {
        if ( !is_array($relatives) )
            $relatives = array ($relatives);
            
        $relativeTemplate = new $relationName();
        if (!$relativeTemplate instanceof Wallop)
        {
            $this->errors[] = 'hasRelatives() cannot take a classname that does not inherit from Wallop!';
            return false;
        }

        if ( isset($this->aggregates[$relationName]) )
        {
            $targetArray = &$this->aggregates;
        }
        else
            if ( isset($this->composites[$relationName]) )
            {
                $targetArray = &$this->composites;
            }
            else
            {
                $this->errors[] = 'The relation \''.$relationName.'\' is not specified in this class!';
                return false;
            }

        $relationTableName = $targetArray[$relationName]['relationTableName'];

        // The lower-camel cased column name of the relation table
        $relativeColumn = self::columnNameGenerator($relativeTemplate->objectInfo['tableName']);

        // if the relative is the same type
        if ( $relativeTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
           $relativeColumn .= '2';

        $thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

        $query  = 'SELECT `'.$relativeColumn.'` AS id FROM '.$relationTableName;
        $query .= ' WHERE `'.$thisColumn.'`='.$this->id;

        global $database;
        $database->execQuery($query);
        $rows = $database->getAllRows();

        $ids = array();
        foreach ($rows as $row)
            $ids[$row['id']] = true;

        if ( isset($targetArray[$relationName]['newObjects']) )
            foreach ( array_keys($targetArray[$relationName]['newObjects']) as $pseudoId )
            {
                $id = $targetArray[$relationName]['objects'][$pseudoId]->id;
                $ids[$id] = true;
            }

        print_r($ids);

        $output = array();
        foreach($relatives as $relative)
        {
            $id = $relative->getId();

            if ( !$relative instanceof $relationName )
            {
                $this->errors[] = 'Object with id='.$id.' is not of type '.$relationName.'!';
                $output[$id] = false;
                continue;
            }

            if ( $ids[$id] )
                $output[$id] = true;
            else
                $output[$id] = false;
        }

        return $output;
    }
    // ==========================================================================================
	// End of functions related to relations
	
	

    // Static functions mostly used for working internally with an object
	// ==========================================================================================   

    /* Returns the array of errors set by static functions
     */
    public static function getStaticErrors()
    {
        $temp = self::$staticErrors;
        self::$staticErrors = array();

        return $temp;
    }

    /* Returns the column name of relation table given
     * the name of an objects table
     */
    private static function columnNameGenerator($tableName)
    {
        $tableName{0} = strtolower($tableName{0});
        $tableName .= 'Id';

        return $tableName;
    }

    /* Takes an object to remove
     * This function is just the internal recursive remove function for handling composite
     * relatives
     */
    private static function removeRecursive($object, $id = 0)
    {
        if ( isset($object->id) )
        {
            $objectId = $object->id;
            unset($object->id);
        }
        else
        {
            $objectId = $id;
        }

        global $database;
        $query = 'DELETE FROM `'.$object->objectInfo['tableName'].'` WHERE `id` = ? LIMIT 1';
        $database->execQuery($query, $objectId);

        $columnName = self::columnNameGenerator($object->objectInfo['tableName']);

        foreach($object->aggregates as $aggregate)
        {
            $aggregateTableName = $aggregate['relationTableName'];
            $query = 'DELETE FROM `'.$aggregateTableName.'` WHERE `'.$columnName.'` = ?';
            $database->execQuery($query, $objectId);
        }

        foreach ($object->composites as $compClassName => $composite)
        {
            $compObj = new $compClassName();
            $compColumnName = self::columnNameGenerator($compObj->objectInfo['tableName']);

            // if the relative is the same type
            if ( $compObj->objectInfo['tableName'] == $object->objectInfo['tableName'] )
                $compColumnName .= '2';

            $query  = 'SELECT `'.$compColumnName.'` FROM `'.$composite['relationTableName'].'` WHERE `'.$columnName.'`='.$objectId;
            $database->execQuery($query);
            $rows = $database->getAllRows();
          
            $compIds = array();
            foreach ($rows as $row)
                $compIds[] = $row[$compColumnName];

            // Now that we have retrieved the ids of our composites
            // remove all relations for this composite before recursing (just in-case)
            $query = 'DELETE FROM `'.$composite['relationTableName'].'` WHERE `'.$columnName.'`='.$objectId;
            $database->execQuery($query);


            // Foreach relation of this composite check to see if it has a composite
            // relation with this composite as well
            $compRelations = array_merge($compObj->aggregates, $compObj->composites);
            foreach ($compRelations as $relationClassName => $compRelation)
            {
                $relationObj = new $relationClassName();
                $relationColumnName = self::columnNameGenerator($relationObj->objectInfo['tableName']);

                if ( isset($relationObj->composites[$compClassName]) )
                {
                    // if it does then see if any of this composites ids are currently
                    // related from those relations
                    foreach ($compIds as $compId)
                    {   
                        $query  = 'SELECT `'.$relationColumnName.'` FROM `'.$compRelation['relationTableName'].'` ';
                        $query .= 'WHERE `'.$compColumnName.'` = '.$compId.' LIMIT 1';
                        $database->execQuery($query);

                        if ( !$database->getRow() )
                            self::removeRecursive($compObj, $compId);
                    }
                }
            }
        } // End of the composite foreach loop

        return true;
    }
	
    /* Returns an $amount number of $tableName objects in the $sort order with $conditions
     */
    public static function getMany($className = null, $amount = -1, $start = 0, $sortBy = '', $sort = 'ASC', $conditions = '')
    {
        if ($className == null || $className == '')
        {
            self::$staticErrors[] = 'getMany() must take at least the name of the class to get records for!';
            return false;
        }

        // Instantiate the type of object to get records for
        $object = new $className();
        $object->constructTemplate();
        $info = $object->getObjectInfo();
        $tableName = $info['tableName'];
        $columns = $object->getColumns();

        if ($amount < -1)
        {
            self::$staticErrors[] = '$amount must only 0 or higher, or -1 for getting all records.';
            return false;
        }
        
        if ($start < 0)
        {
            self::$staticErrors[] = '$start must only take a positive number!';
            return false;
        }

        if ( $sortBy != '' )
            if ( !is_string($sortBy) )
            {
                self::$staticErrors[] = '$sortBy must be a string!';
                return false;
            }
            else
            {
                $found = false;
                foreach( $columns as $column )
                    if ($column == $sortBy)
                    {
                        $found = true;
                        break;
                    }

                if ( !$found )
                {
                    self::$staticErrors[] = '$sortBy must reference an existing column!';
                    return false;
                }

                $sort = strtoupper($sort);
                if ($sort != 'ASC' && $sort != 'DESC')
                {
                    self::$staticErrors[] = '$sort must only be \'ASC\' or \'DESC\'!';
                    return false;
                }
            }

        if ( $conditions != '' )
            if ( !is_string($conditions) )
            {
                self::$staticErrors[] = '$conditions must be a string!';
                return false;
            }
        // end of validation
        // we now assume all input is valid

        global $database;
        $query = 'SELECT * FROM '.$tableName.' ';
        
        if ($conditions != '')
            $query .= 'WHERE '.$conditions.' ';

        if ($sortBy != '')
            $query .= 'ORDER BY '.$sortBy.' '.$sort.' ';

        if ($amount != -1)
            $query .= 'LIMIT '.$start.', '.$amount.' ';

        $result = $database->execQuery($query);
        if (!$result)
        {
            self::$staticErrors[] = 'The database query failed for some reason!';
            return false;
        }

        $rows = $database->getAllRows();
        if (!$rows)
        {
            self::$staticErrors[] = 'Failed to get the records from the database!';
            return false;
        }

        $objects = array();
        foreach($rows as $row)
        {
            $object = new $className();
            $object->columns = $columns;
            foreach($columns as $column)
            {
                $object->id = $row['id'];
                $object->record[$column] = $row[$column];
            }

            $objects[] = $object;
        }

        return $objects;
    }
	// ==========================================================================================
	// End of static functions


	
	// Functions for helping to install your database
	// ==========================================================================================
	
	// These functions will require that the database user provided has the permissions to 
	// modify tables
	
	/* This function installs the relational tables in the database for this object
	 */
    public function createRelational()
    {
        global $database;
        $ar = array();
        
        if(count($this->aggregates) > 0)
        {
            foreach($this->aggregates as $aggregate)
            {
                $ar[] = $aggregate['relationTableName'];
            }
        }
        if(count($this->composites) > 0)
        {
            foreach($this->composites as $composite)
            {
                $ar[] = $composite['relationTableName'];
            }
        }
        $col1 = $this->objectInfo['className'];
        foreach($ar as $col2)
        {
            $this->createIdColumn($col1);
            $this->createIdColumn($col2);

            $col1Name = self::columnNameGenerator($col1);
            $col2Name = self::columnNameGenerator($col2);

            $col1Name{0} = strtoupper($col1Name{0});
            $col2Name{0} = strtoupper($col2Name{0});

            $sortAr = array($col1Name, $col2Name);
            sort($sortAr, SORT_STRING);
            $tableName = $sortAr[0].'To'.$sortAr[1];

            $query = <<<TEMPLATE
                CREATE TABLE IF NOT EXISTS `{$tableName}` (
                        {$col1} INT(10) unsigned NOT NULL,
                        {$col2} INT(10) unsigned NOT NULL,

                        PRIMARY KEY(`{$col1}`,`{$col2}`)
                       )
                ENGINE=InnoDB DEFAULT CHARSET = latin1
TEMPLATE;
/*
                        CONSTRAINT `{$tableName}_ibfk_1` FOREIGN KEY (`{$col1}Id`) REFERENCES `{$col1Name}` (`id`),
                        CONSTRAINT `{$tableName}_ibfk_2` FOREIGN KEY (`{$col2}Id`) REFERENCES `{$col2Name}` (`id`)

*/
            echo $query.'<br /><br />';
            $database->execQuery($query);

            
        }
    }

	/* This function creates the primary key (id) for this object
	 */
    public function createIdColumn($table)
    {
        global $database;
        $database->execQuery('SHOW COLUMNS FROM `$table` WHERE `field` = \'id\'');
        $row = $database->getRow();
        if(!isset($row['id']))
        {
            $database->execQuery('ALTER TABLE `$table` DROP PRIMARY KEY');
            $database->execQuery('ALTER TABLE `$table` ADD `id` INT NOT NULL PRIMARY KEY AUTO_INCREMENT');
        }
    }
	// ==========================================================================================
	// End of database installation functions
}

?>
