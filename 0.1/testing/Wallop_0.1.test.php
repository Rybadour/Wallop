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
$_WALLOP['mysql']['database'] = 'WallopTesting';
$_WALLOP['mysql']['user']     = 'tester';
$_WALLOP['mysql']['password'] = 'yj8t3g';

require_once('WallopDatabase.php');
global $database;

$database = new WallopDatabase();
$database->connect();



/* Wallop v0.1
 * In this version the system is highly simplified.
 * It lacks features that provide additional flexibility in the design of your object and efficiency
 * since you can perform actions closer to what you want.
 */
abstract class Wallop
{
	// Class members
	private static $staticErrors = array();
	
    private $id;
    private $objectInfo = array(); // ['tableName'] => 'Table Name', 
								   // ['valueChanged'] => (bool)$hasARecordChanged,
								   // ['toBeRemoved'] => (bool)$isMarkedForRemoval
    private $aggregates = array(); // *See below
    private $composites = array(); // *See below
    private $columns    = array(); // [] => 'Column Name'
    private $record     = array(); // ['Column Name'] => $columnValue;
    private $errors     = array(); // [] => 'Error msg';
	
	/* $aggregates and $composites: (they have the same structure)
	 *
	 * ['Relation Class Name'] =>
	 * 		array( ['relationTableName']       => 'Relation Table Name',
	 *             ['objects']                 => array( [$relative->id] => $relativeObj ),
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
     * $tableName (string): the table name for this object in the database
     * $id (int): the primary key of a single object from the database, If this is set than the object
	 *            will be initialized using that value, otherwise the object is left 'empty'
     * $relations: Array of associative arrays detailing the relation between
     *             this class and other objects
     *   -> 'className' (string): the class name of the other object
     *   -> 'relationTableName' (string): the name of the relation table
     *   -> 'dependency' ('composite', 'aggregate'): whether the other object is necessary for
     *                                this object to exist
     */
    public function Wallop($tableName, $id = null, array $relations = null)
    {
        // Validate parameters	
		if ( !is_string($tableName) || $tableName == '')
		{
			$this->errors[] = 'Constructor: $tableName must be a non-empty string!';
			return;
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
                    $this->errors[] = 'Constructor arg $relations must be an array of arrays.';
                    return;
                }
                
                if ( !isset($relation['className']) || $relation['className'] == '' ||
				     !isset($relation['relationTableName']) || $relation['relationTableName'] == '')
                {
                    $error = 'Constructor arg $relations must have arrays that all';
                    $error .= ' contain \'className\' and \'relationTableName\'.';
                    $this->errors[] = $error;
                    return;
                }
				if ( !is_string($relation['className']) || !is_string($relation['relationTableName']) )
				{
					$error  = 'Arg $relations must only have arrays where \'className\' ';
					$error .= 'and \'relationTableName\' are strings!';
					$this->errors[] = $error;
					return;
				}
				
				// Check if this className refers to a Wallop inheriting object
				if ( !class_exists($relation['className']) || !is_subclass_of($relation['className'], 'Wallop') )
				{
					$error  = 'Constructor: Relation \''.$relation['className'].'\' does not inherit ';
					$error .= 'Wallop!';
					$this->errors[] = $error;
					return;
				}
				
                if ( !isset($relation['dependency']) || $relation['dependency'] == '' ||
				     !is_scalar($relation['dependency']) )
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
			unset($relation);

            // Only build it after all relations are checked
            foreach ($relations as &$relation)
            {
                if ($relation['dependency'] == 'aggregate')
                {
                    $this->aggregates[$relation['className']]['relationTableName'] 
						= $relation['relationTableName'];
                }
                else
                {
                    $this->composites[$relation['className']]['relationTableName']
						= $relation['relationTableName'];
                }
            }
			unset($relation);
        }

        // At this point we assume all data is set properly
        $this->objectInfo['tableName'] = $tableName;
        
        if ( isset($id) )
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
            $error = 'Cannot get columns for table \''.$this->objectInfo['tableName'].'\' !';
			$this->errors[] = $error;
            return false;
        }

        $this->columns = array();
        
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
            $this->columns[] = $row['Field'];
            $this->record[$row['Field']] = '';
			
			++$i;
        }

        return true;
    }

    /* Returns false if no id is passed, and true if the intialization
     * is successful
     * Iniatilizes the object using the id to query the database
     * and fill the object
     */
    public function initialize($id)
    {
        if ( !is_scalar($id) )
		{
			$this->errors[] = 'Cannot initialize with the given $id, $id must not be an array or object!';
		}

		// The template of this object must be setup first
        $this->constructTemplate();

        global $database;

        // Setup the select field
        $columns = '';
		$i = 0;
		$size = count($this->columns);
        while ($i != $size)
		{
            $columns .= '`'. $this->columns[$i] .'`,';
			
			++$i;
		}
        $columns = trim($columns, ',');

		$query  = 'SELECT '.$columns.' FROM `'.$this->objectInfo['tableName'].'` WHERE `id`=? ';
		$query .= 'LIMIT 1';
        $result = $database->execQuery($query, $id);
        if (!$result)
        {
            $error  = 'Unable to Query record from Table \''.$this->objectInfo['tableName'];
			$error .= '\' may not exist!';
			$this->errors[] = $error;
            return false;
        }

        $row = $database->getRow();
        if ( !$row )
        {
            $error  = 'No record of the \''.$this->objectInfo['tableName'].'\' table has an id ';
			$error .= 'of \''.$id.'\'!';
			$this->errors[] = $error;
            return false;
        }

        $this->id = $id;
		$i = 0;
		$size = count($this->columns);
        while ($i != $size)
		{
            $this->record[$this->columns[$i]] = $row[$this->columns[$i]];
			
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
        $this->constructTemplate();

        // Check if column even exists
        if ( !in_array($column, $this->columns) )
        {
            $error = 'Cannot get value \''.$column.'\', that column does not exist in this class!';
			$this->errors[] = $error;
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
            $error = 'Cannot set \''.$column.'\', that column does not exist in this object!';
			$this->errors[] = $error;
            return false;
        }
		
		// Ensure that $value is a primitive type
		if ( !is_scalar($value) )
		{
			$error = 'Cannot set \''.$column.'\', the value given is either an array or an object!';
			$this->errors[] = $error;
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
    public function commit($handleRelatives = true, $commitRelatives = true, $keepRelativesOnRemove = false)
    {
        global $database;

        $this->constructTemplate();

        // Since the array of values to set is going to
        // be identical for both cases, we may as well
        // assemble it beforehand. Let's do that.
		$values = array();
		$i = 0;
		$numColumns = count($this->columns);
        while ($i != $numColumns)
		{
            $values[] = $this->record[$this->columns[$i]];
			
			++$i;
		}

        // If it exists in the database,
        // build a query to update it.
        if ( isset($this->id) )
        {
            if ( isset($this->objectInfo['toBeRemoved']) && $this->objectInfo['toBeRemoved'] )
            {
                // Removes the record from the database if it is flagged to be removed
                // Calls remove on composites
                return self::removeRecursive($this, 0, $keepRelativesOnRemove);
            }

            // Only update the database if some value was change in the set function
            if ( isset($this->objectInfo['valueChanged']) && $this->objectInfo['valueChanged'] )
            {
                $query = 'UPDATE `'.$this->objectInfo['tableName'].'`
                          SET ';

				$i = 0;
                while ($i != $numColumns)
				{
                    $query .= '`'.$this->columns[$i].'` = ?,';
					
					++$i;
				}
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

			$i = 0;
            while ($i != $numColumns)
			{
                $query .= '`'.$this->columns[$i].'`,';
				
				++$i;
			}
            $query = trim ($query, ',');

            $query .= ') VALUES (';

			$i = 0;
            while ($i != $numColumns)
			{
                $query .= '?,';
				
				++$i;
			}
            $query = trim ($query, ',');

            $query .= ')';

			$this->id = $database->execQueryWith($query, $values);
			if (!$this->id)
			{
				unset($this->id);
				
				$error  = 'commit(): There was an error when trying to insert the new object, because: ';
				$error .= $database->error;
				$this->errors[] = $error;
				return false;
			}
        }
		
		unset($this->objectInfo['valueChanged']);

		// End the function before checking relatives if
		// this is only a simple commit
		if (!$handleRelatives)
			return true;
		
		// Handle relatives of this object
		$relationTypes = array('aggregates' => &$this->aggregates, 
		                       'composites' => &$this->composites);
        foreach ($relationTypes as $relationTypeName => &$relationType)
		{
			foreach ($relationType as $relationClassName => &$relation)
			{
				$relationTemplate = new $relationClassName();
				if ( !$relationTemplate instanceof Wallop )
				{
					$error  = 'commit(): Cannot continue with relation \''.$relationClassName.'\' ';
					$error .= 'the relation does not inherit Wallop!';
					$this->errors[] = $error;
					continue;
				}
				
				if ( !empty($relationTemplate->errors) )
				{
					$error  = 'commit(): Cannot continue with relation \''.$relationClassName.'\' ';
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
						if ( $relation['uninitializedNewObjects'][$i]->commit(true, true) )
						{
							$id = $relation['uninitializedNewObjects'][$i]->id;
							$relation['objects'][$id]	= $relation['uninitializedNewObjects'][$i];
							$relation['newObjects'][$id] = true;
							
							unset($relation['uninitializedNewObjects'][$i]);
						}
						else
						{
							$relativeErrors = $relative->getErrors();
							$error  = 'A problem occurred when trying to commit an uninitialized'; 
							$error .= ' object of type '.$relationClassName.' with the following';
							$error .= ' error: "'.$relativeErrors[0].'"';					
							$this->errors[] = $error;
						}
						
						++$i;
					}
				}
			
				// In either of the below cases we will need to initialize some values
				if( isset($relation['objects']) || isset($relation['removedObjects']) )
				{
					$relativeColumn = 
						self::columnNameGenerator($relationTemplate->objectInfo['tableName']);

					// if the relative is the same type
					if ( $relationTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
						$relativeColumn .= '2';

					$thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);
				}
				else
					continue;
					
				
				// If they are any objects marked for removal
				if ( isset($relation['removedObjects']) )
				{
					// Create the string filled with ids of objects to remove
					// from the relation table between this object and this relation
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
						// TODO: $compsToRemove is not setup properly making this function fail
						$compIds = self::hasNoCompositeRelatives($relationTemplate, $relationClassName, $relativeColumn,
						                                         $relation['relationTableName'], 
														         $this->id, $thisColumn, $compsToRemove);
					}
					
					// Create the query to remove relatives from the relation table
					$query  = 'DELETE FROM `'.$relation['relationTableName'].'` ';
					$query .= 'WHERE `'.$relativeColumn.'` IN '.$idsToRemove.' && `'.$thisColumn.'`=';
					$query .= $this->id.' LIMIT '.$numToRemove;
					$database->execQuery($query);
					
					// After the deletion of the relation table records
					if ($relationTypeName == 'composites' && !empty($compIds))
					{
						$size = count($compIds);
						$i = 0;
						while ($i != $size)
						{
							self::removeRecursive($relationTemplate, $compIds[$i], false);
							
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
						// Search for newObjects to add relations for in the database
						// and add a relation in the database for them
						$query  = 'INSERT INTO `'.$relation['relationTableName'].'` ';
						$query .= '(`'.$relativeColumn.'`,`'.$thisColumn.'`) VALUES';
						foreach ($relation['newObjects'] as $newId => $unused)
						{
							$query .= '('.$newId.','.$this->id.'),';
							
							// Also remove the new relative from the stored relatives list
							unset($relation['objects'][$newId]);
						}
						$query = trim($query, ',');
						
						$database->execQuery($query);
						
						
						// Iterate through storedGets and remove them if their result set changed
						if ( isset($relation['storedGets']) )
						{
							$i = 0;
							$numStoredGets = count($relation['storedGets']);
							while ($i != $numStoredGets)
							{
								// TODO: Implement this function
								if ( checkNewAgainstStoredGet($relationClassName, 
														      $relation['storedGets'][$i], 
															  $newInList) 
									)
									unset($relation['storedGets'][$i]);
									
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
											  array &$storedGet, array &$newInList)
	{
		// Create the query to check for the newObjects in the result set
		// of the storedGet's settings
		
        // The lower-camel cased column name of the relation table
		$thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);
        $newObjColumn = self::columnNameGenerator($newObjTemplate->objectInfo['tableName']);

        // If the relative is the same type
        if ( $newObjTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
           $newObjColumn .= '2'; 

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
            $this->errors[] = 'Could not query the database to get relatives!';
            return false;
        }
		
		$row = $database->getRow();
		if (!$row)
		{
			$error = 'Apparently my private function can\'t do a private function without failing.';
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
        if ( !isset($this->objectInfo['toBeRemoved']) || !$this->objectInfo['toBeRemoved'] )
        {
            $this->errors[] = 'Cannot unremove this object, it is not already flagged for removal!';
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
            unset($this->errors);
            $this->errors = array();
        }
        return $temp;
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
            $error = 'getInternalRelatives() must take a non-null $relationName that is a string!';
			$this->errors[] = $error;
            return false;
        }
		
		// Ensure that the relation exists in this object
		// Whilst at the same time setting the targetArray for later use
        if ( isset($this->aggregates[$relationName]) )
		{
			$targetArray = &$this->aggregates[$relationName];
		}
		else
		{
			if ( isset($this->composites[$relationName]) )
			{
				$targetArray = &$this->composites[$relationName];
			}
			else
			{
				$error  = 'Cannot retrieve relatives for relation \''.$relationName;
				$error .= '\' it does not exist in this object!';
				$this->errors[] = $error;
				return false;
			}
		}
		
		//Assume the relationName is validated
        $relativeTemplate = new $relationName();
        if ( !$relativeTemplate->constructTemplate() )
        {
            $this->errors = array_merge($this->errors, $relativeTemplate->getErrors());
            $this->errors[] = 'Could not construct a template of \''.$relationName.'\'!';
            return false;
        }
		if ( !empty($relationTemplate->errors) )
		{
			$error  = 'getNewRelatives(): Cannot continue with relation ';
			$error .= '\''.$relationClassName.'\', the relation does not construct without error, check ';
			$error .= 'it\'s configuration! The following Error is the relation\'s error:';
			$this->errors[] = $error;
			$this->errors[] = $relationTemplate->errors[0];
			return false;
		}
		
		// Get the ids of the uncomitted relatives from the objects array
		$relatives = array();
		foreach ($targetArray['newObjects'] as $id => $unused)
		{
			$relatives[] = $targetArray['objects'][$id];
		}
		foreach ($targetArray['uninitializedNewObjects'] as $id => $unused)
		{
			$relatives[] = $targetArray['objects'][$id];
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
            $error  = 'Cannot retrieve relatives when this object is not initialized! ';
			$error .= 'If you want to retrieve relatives stored in this object (and not from the ';
			$error .= 'database) then use the getInternalRelatives() function ';
			$this->errors[] = $error;
            return false;
        }

        if ( $relationName == '' || !is_string($relationName) )
        {
            $error  = 'getRelatives() must take a non-null $relationName that is not an array';
			$error .= ' or object!';
			$this->errors[] = $error;
            return false;
        }
       
        // Ensure that the relation exists in this object
		// Whilst at the same time setting the targetArray for later use
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
				$error  = 'Cannot retrieve relatives for relation \''.$relationName.'\' it does ';
				$error .= 'not exist in this object!';
				$this->errors[] = $error;
				return false;
			}
		}

        //Assume the relationName is validated
        $relativeTemplate = new $relationName();
        if ( !$relativeTemplate->constructTemplate() )
        {
			$this->errors[] = 'Could not construct a template of \''.$relationName.'\'!';
			$this->errors[] = 'getRelatives: Errors after this are for $relativeTemplate:';
            $this->errors = array_merge($this->errors, $relativeTemplate->getErrors());
            $this->errors[] = 'End of errors from $relativeTemplate';
            return false;
        }
		if ( !empty($relationTemplate->errors) )
		{
			$error  = 'commit(): Cannot continue with relation \''.$relationName.'\' ';
			$error .= 'the relation does not construct without error, check it\'s configuration!';
			$error .= 'The following error is the relation\'s error:';
			$this->errors[] = $error;
			$this->errors[] = $relationTemplate->errors[0];
			return false;
		}
		
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
			$this->errors[] = 'getMany(); $amount cannot be less than -1!';
		}
        if ($amount == -1)
            $amount = '18446744073709551615';
		
		// Validate $sortBy and $sort only if $sortBy is actually set 
		if ($sortBy != '')
		{
			if ( !is_string($sortBy) || !in_array($sortBy, $relativeTemplate->columns))
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
			$this->errors[] = 'getRelatives(): $conditions must be a sting!';
		}
				
		// First check to see if this query has been made already
		if ( isset($targetArray[$relationName]['storedGets']) )
		{
			$i = 0;
			$size = count($targetArray[$relationName]['storedGets']);
			while ($i != $size)
			{
				$storedGet = $targetArray[$relationName]['storedGets'][$i];
				
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
					if ( !$replaceWithModified && isset($targetArray[$relationName]['originalObjects'][$id]) )
						$relatives[] = $targetArray[$relationName]['originalObjects'][$id];
					else
						$relatives[] = $targetArray[$relationName]['objects'][$id];
				}
				
				// Then return them
				return $relatives;
			}
		}

		
		// Create the query to get the relatives
		
        // The lower-camel cased column name of the relation table
        $relativeColumn = self::columnNameGenerator($relativeTemplate->objectInfo['tableName']);

        // If the relative is the same type
        if ( $relativeTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
           $relativeColumn .= '2'; 

        $thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

        $query = 'SELECT `id`,';
		$i = 0;
		$size = count($relativeTemplate->columns);
        while ($i != $size)
		{
            $query .= '`'.$relativeTemplate->columns[$i].'`,';
			
			++$i;
		}
        $query = trim($query, ',');

        $query .= ' FROM `'.$relativeTemplate->objectInfo['tableName'].'` AS other,';
            $query .= '(SELECT `'.$relativeColumn.'` FROM ';
            $query .= '`'.$targetArray[$relationName]['relationTableName'].'` ';
            $query .= 'WHERE `'.$thisColumn.'`='.$this->id.' LIMIT '.$start.','.$amount.') ';
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

			
        global $database;
        $result = $database->execQuery($query);
		
        if (!$result)
        {
            $error  = 'Could not query the database to get relatives! Because: ';
			$error .= $database->error;
			$this->errors[] = $error;
			$this->errors[] = 'Query was: \''.$query.'\'';
            return false;
        }

        $rows = $database->getAllRows();
        if (!$rows)
        {	
			// It might just be empty
			if ( is_array($rows) )
				return array();
							
            $this->errors[] = 'Failed to get the records of the relatives from the database!';
            return false;
        }
		
		// Create the relatives in object form
		$relatives = array();
		$i = 0;
		$numRows = count($rows);
        while ($i != $numRows)
        {
			// If the flag to replace the relative with internal is set then replace
			if ( $replaceWithModified && isset($targetArray[$relationName]['modifiedObjects'][$rows[$i]['id']]) )
			{
				$relative = $targetArray[$relationName]['modifiedObjects'][$rows[$i]['id']];
			}
			else
			{
				$relative = new $relationName();
				$relative->columns = $relativeTemplate->columns;
				$colId = 0;
				$numCols = count($relative->columns);
				while ($colId != $numCols)
				{
					$column = $relative->columns[$colId];
				
					$relative->id = $rows[$i]['id'];
					$relative->record[$column] = $rows[$i][$column];
					
					++$colId;
				}
			}

            // store relatives by their index from the query
            $relatives[] = $relative;
			
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
				if ( !isset($targetArray[$relationName]['objects'][$relId]) )
					$targetArray[$relationName]['objects'][$relId] = $relative;
					
				// If this relative is already being stored as a modified object
				// we need to save this relative in the originalObjects array
				if ( isset($targetArray[$relationName]['modifiedObjects'][$relId]) &&
				     !isset($targetArray[$relationName]['originalObjects'][$relId]) )
					$targetArray[$relationName]['originalObjects'][$relId] = $relative;
					
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
			
			$targetArray[$relationName]['storedGets'][] = $storedGet;
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
				foreach ($relation['objects'] as $objId => $unused)
				{
					if ( !isset($relation['modifiedObjects'][$objId]) &&
						 !isset($relation['newObjects'][$objId]) )
					{
						unset($relation['objects'][$objId]);
					}
				}
			}
					
			unset($relation['storedGets']);
			unset($relation['originalObjects']);
		};
		
		$relationTypes = array();
		$relationTypes[] = &$this->aggregates;
		$relationTypes[] = &$this->composites;
		
		foreach ($relationTypes as &$relationType)
		{
			if ( isset($relationToRemove) )
			{
				if ( isset($relationType[$relationToRemove]) )
				{
					$relation = &$relationType[$relationToRemove];
					
					$unsetRelation($relation, $unsetAll);
				}
			}
		
			foreach ($relationType as $relationName => &$relation)
			{	
				$unsetRelation($relation, $unsetAll);
			}
		}
		
		return true;
	}
	
    /* Takes a type of Wallop and an array of Wallops of that type
     * Adds them to the appropriate array in this Wallop
     * (i.e. either $aggregates or $composites)
     */
    public function setRelatives($relationName, array $relatives)
    {
		if ( empty($relatives) )
		{
			return true;
		}
	
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'In getRelatives(): $relationName must be a non-empty string!';
			return false;
		}
	
        // Check to see if this is an aggregate or a composite
        // Does this type exist in $aggregates?
        if ( isset($this->aggregates[$relationName]) )
        {
            // Yeah? Then we're using $aggregates
            $targetArray = &$this->aggregates;
        }
        
        // No? Well, does it exist in $composites?
        else if ( isset($this->composites[$relationName]) )
        {
            // Yeah? Then we're using $composites
            $targetArray = &$this->composites;
        }

        // It wasn't in either one? Well, fuck - they gave a non-relative Wallop type
        else
        {
            $this->errors[] = 'Tried to add Wallops of a non-relative type!';
            return false;
        }

		// Get an array of relatives and if they exist for this object in the database
		$arrHasRelatives = $this->hasRelatives($relationName, $relatives, false, true);
		if ( is_bool($arrHasRelatives) && !$arrHasRelatives )
		{
			$error = 'setRelatives(): hasRelatives() failed to check relatives! See previous errors.';
			$this->errors = $error;
			return false;
		}
		
		// Set the target array to the relation's array
		$targetArray = &$targetArray[$relationName];
        foreach ($relatives as &$relative)
        {
            // Make sure this Wallop is an instance of the Wallop type they specified.
            if ( !($relative instanceof $relationName) )
            {
                $this->errors[] = 'Tried to add a Wallop of the wrong type!';
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
					if ( !isset($targetArray['newObjects'][$id]) )
						$targetArray['modifiedObjects'][$id] = true;

					// Remove any removal marks for this relative
					if( isset($targetArray[$relationName]['removedObjects']) )
						unset($targetArray['removedObjects'][$relative->id]);
				}
				else
				{
					$targetArray['newObjects'][$id] = true;
				}
				
				// Before we override the object at this id, check if a storedGet maps to it
				if ( !isset($targetArray['originalObjects'][$id]) && isset($targetArray['storedGets']) )
				{
					// Check all storedGets arrays for this id in it's map
					$found = false;
					$i = 0;
					$numOfStoredGets = count($targetArray['storedGets']);
					while ($i != $numOfStoredGets)
					{
						if ( isset($targetArray['storedGets'][$i]['mapOfIds'][$id]) )
						{
							$found = true;
							break;
						}
						
						++$i;
					}
					
					if ($found)
					{
						$targetArray['originalObjects'][$id] = $targetArray['objects'][$id];
					}
				}
				
				$targetArray['objects'][$id] = $relative;
			}
			// Otherwise add the relative to the list of uninitialized objects
			else
			{
				$targetArray['uninitializedNewObjects'][] = $relative;
			}
        }
		unset($relative);
    }

    /* Removes relations based on the relation and array of objects provided
     */
    public function removeRelatives( $relationName, array $relatives = array() )
    {
		if ( empty($relatives) )
		{
			return true;
		}
	
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'In removeRelatives(): $relationName must be a non-empty string!';
			return false;
		}
	
		// Setup a template for the relative's object
        if ( !class_exists($relationName) || !is_subclass_of($relationName, 'Wallop') )
        {
            $error = 'Cannot remove relation '.$relationName.' it is does not inherit from Wallop!';
			$this->errors[] = $error;
            return false;
        }

		// Set the target array to the dependency of this relative
		if( isset($this->aggregates[$relationName]) )
		{
			$targetArray = &$this->aggregates;
		}
		else
		{
			if( isset($this->composites[$relationName]) )
			{
				$targetArray = &$this->composites;
			}
			else
			{
				$error  = 'Cannot remove relation '.$relationName.' it does not relate to this ';
				$error .= 'object!';
				$this->errors[] = $error;
				return false;
			}
		}
			
        foreach($relatives as &$relative)
        {
			// If this relative isn't a Wallop object
			// Ignore it but notify
			if ( !$relative instanceof Wallop )
			{
				$this->errors[] = 'Cannot remove relative it is not a Wallop object!';
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
			if ( !$relative instanceof $relationName )
			{
				$error  = 'Cannot remove relative with id = '.$relId.' it is not belong to the ';
				$error .= '\''.$relationName.'\' relation!';
				$this->errors[] = $error;
				continue;
			}

			if ( isset($this->id) || isset($targetArray[$relationName]['newObjects'][$relId]) )
			{
				$targetArray[$relationName]['removedObjects'][$relId] = true;
			
				if ( isset($targetArray[$relationName]['objects'][$relId]) )
					$targetArray[$relationName]['removedStoredObjects'][$relId] = true;
			}
        }
		unset($relative);
    }

    /* Returns an array of id=>true/false
     * (if the object's id exists in the database)
     */
    public function hasRelatives($relationName, array $relatives, 
								 $checkStoredRelatives = false, $returnOnlyTrue = false)
    {    
		if ( $relationName == '' || !is_string($relationName) )
		{
			$this->errors[] = 'In hasRelatives(): $relationName must be a non-empty string!';
			return false;
		}
	
		if ( empty($relatives) )
			return array();
	
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
                $error = 'The relation \''.$relationName.'\' is not specified in this class!';
				$this->errors[] = $error;
                return false;
            }
		}
		
		$relativeTemplate = new $relationName();
        if (!$relativeTemplate instanceof Wallop)
        {
            $error = 'hasRelatives() cannot take a classname that does not inherit from Wallop!';
			$this->errors[] = $error;
            return false;
        }

		$output = array();
		if ( isset($this->id) )
		{
			$relationTableName = $targetArray[$relationName]['relationTableName'];

			// The lower-camel cased column name of the relation table
			$relativeColumn = self::columnNameGenerator($relativeTemplate->objectInfo['tableName']);

			// if the relative is the same type
			if ( $relativeTemplate->objectInfo['tableName'] == $this->objectInfo['tableName'] )
			   $relativeColumn .= '2';

			$thisColumn = self::columnNameGenerator($this->objectInfo['tableName']);

			// Create an in list of relative's ids
			$inList = '(';
			foreach($relatives as &$relative)
			{
				$id = $relative->getId();
				
				if ( !$id )
				{
					// If the flag $returnOnlyTrue is set, no reason to give an error
					if ( !$returnOnlyTrue )
					{
						$error  = 'Object passed to hasRelatives() of type '.$relationName;
						$error .= ' is not initialized!';
						$this->errors[] = $error;
					}
					continue;
				}

				if ( !$relative instanceof $relationName )
				{
					$this->errors[] = 'Object with id='.$id.' is not of type '.$relationName.'!';
					
					if ( !$returnOnlyTrue )
						$output[$id] = false;
						
					continue;
				}
				
				if ( !$returnOnlyTrue )
					$output[$id] = false;
					
				$inList .= $id.',';
			}
			unset($relative);
			$inList = trim($inList, ',').')';
			
			$query  = 'SELECT `'.$relativeColumn.'` AS id FROM `'.$relationTableName.'`';
			$query .= ' WHERE `'.$thisColumn.'`='.$this->id.' AND `'.$relativeColumn.'` IN '.$inList;

			global $database;
			
			if ( !$database->execQuery($query) )
			{
				$error  = 'hasRelatives(): The query to check the database failed -- '.$database->error;
				$this->errors[] = $error;
				return false;
			}
			$rows = $database->getAllRows();

			if (!$rows || !is_array($rows))
				$rows = array();
			
			$i = 0;
			$numRows = count($rows);
			while ($i != $numRows)
			{
				$output[$rows[$i]['id']] = true;
				
				++$i;
			}
		}

		// Relatives marked as new will be set to true
        if ( isset($targetArray[$relationName]['newObjects']) )
		{
            foreach ($targetArray[$relationName]['newObjects'] as $newId => $unused)
            {
				if ( isset($output[$newId]) )
					$output[$newId] = true;
            }
		}
		
		// Relatives marked for removal will be set to false
		if ( isset($targetArray[$relationName]['removedStoredObjects']) && !$returnOnlyTrue)
		{
			foreach  ($targetArray[$relationName]['removedStoredObjects'] as $remId => $unused)
			{
				if ( isset($output[$remId]) )
					$output[$remId] = false;
			}
		}
		
        return $output;
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
		
        $columnName = self::columnNameGenerator($object->objectInfo['tableName']);

		global $database;
        foreach($object->aggregates as $aggrClassName => &$aggregate)
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
			
				$objs = $object->getRelatives($aggrClassName, 0, -1, '', '', 
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
		
			// Delete the records on the relation table
            $query = 'DELETE FROM `'.$aggregate['relationTableName'].'` WHERE `'.$columnName.'` = ?';
            $database->execQuery($query, $objectId);
        }
		
        foreach ($object->composites as $compClassName => &$composite)
        {
            $compObj = new $compClassName();
            $compColumnName = self::columnNameGenerator($compObj->objectInfo['tableName']);

            // if the relative is the same type
            if ( $compObj->objectInfo['tableName'] == $object->objectInfo['tableName'] )
                $compColumnName .= '2';

				
			// TODO: Leave until after testing, then remove
			/*
            $query  = 'SELECT `'.$compColumnName.'` FROM `'.$composite['relationTableName'].'` '; 
			$query .= 'WHERE `'.$columnName.'`='.$objectId;
            $database->execQuery($query);
            $rows = $database->getAllRows();
          
			// There are no composites related to this object of this type
			if (!$rows)
				break;
		  
			// and remove this
            $compIds = array();
            foreach ($rows as $row)
			{
                $compIds[] = $row[$compColumnName];
			}
			*/

			
            // Now that we have retrieved the ids of our composites
			$compIds = 
				self::hasNoCompositeRelatives($compObj, $compClassName, $compColumnName,
											  $composite['relationTableName'],
											  $objectId, $columnName);
											  
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
			
				$objs = $object->getRelatives($compClassName, 0, -1, '', '', 
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
            $query  = 'DELETE FROM `'.$composite['relationTableName'].'` ';
			$query .= 'WHERE `'.$columnName.'`='.$objectId;
            $database->execQuery($query);
			
			$i = 0;
			$size = count($compIds);
			while ($i != $size)
			{
				self::removeRecursive($compObj, $compIds[$i], false);
				
				++$i;
			}
        } // End of the composite foreach loop
		
		unset($object->id);
		unset($object->objectInfo['toBeRemoved']);
		unset($object->objectInfo['valueChanged']);

        $query = 'DELETE FROM `'.$object->objectInfo['tableName'].'` WHERE `id` = ? LIMIT 1';
        $database->execQuery($query, $objectId);

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
	private static function hasNoCompositeRelatives(Wallop $compObj, $compClassName, $compColumn, 
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
			$database->execQuery($query);
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
			
		// Foreach relation of this composite build keys in the master array for composites that 
		// only have 0 composite relations to them
		$secondCompColumn = self::columnNameGenerator($compObj->objectInfo['tableName']);
		$compRelationTypes = array(&$compObj->aggregates, &$compObj->composites);
		foreach ($compRelationTypes as $compRelationType)
		{
			foreach ($compRelationType as $compRelationClassName => $compRelation)
			{
				// If this relation has the composite also as a composite relation
				if ($thisRelationTable != $compRelation['relationTableName'])
				{
					$compRelationObj = new $compRelationClassName();
					
					if ( isset($compRelationObj->composites[$compClassName]) )
					{
						$query  = "SELECT `{$secondCompColumn}` ";
						$query .= "FROM `{$compRelation['relationTableName']}` ";
						$query .= "WHERE `{$secondCompColumn}` IN {$inList}";

						$database->execQuery($query);
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
    public static function getMany($className, $amount = -1, $start = 0, $sortBy = '',
	                                                  $sort = 'ASC', $conditions = '')
    {
        if ( $className == '' || !is_string($className) )
        {
            $staticError = 'getMany() must take at least the name of the class to get records for!';
			self::$staticErrors[] = $staticError;
            return false;
        }

        // Instantiate the type of object to get records for
        $objectTemplate = new $className();
		if ( !$objectTemplate->constructTemplate() )
        {
			$staticError  = 'getMany(): Could not construct a template of \''.$className.'\'!';
			$staticError .= 'The following is the error from the object:';
			self::$staticErrors[] = $staticError;
			self::$staticErrors[] = $objectTemplate->errors[0];
            return false;
        }
		if ( !empty($objectTemplate->errors) )
		{
			$staticError  = 'commit(): Cannot continue with object \''.$className.'\', ';
			$staticError .= 'the object does not construct without error, check it\'s configuration!';
			$staticError .= 'The following error is the object\'s error:';
			self::$staticErrors[] = $staticError;
			self::$staticErrors[] = $objectTemplate->errors[0];
			return false;
		}
		
        $info = $objectTemplate->getObjectInfo();
        $tableName = $info['tableName'];
        $columns = $objectTemplate->getColumns();

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
				self::$staticErrors[] = '$sort must either be \'ASC\' or \'DESC\'!';
				return false;
			}
		}
		
		//  Validate $conditions
		if ( !is_string($conditions) )
		{
			self::$staticErrors[] = '$conditions must be a sting!';
		}
        // End of validation
        // We now assume all input is valid

        global $database;
        $query = 'SELECT '; 
		$i = 0;
		$numColumns = count($columns);
		while ($i != $numColumns)
		{
			$query .= $columns[$i].',';
			++$i;
		}
		$query .= ' FROM '.$tableName.' ';
        
        if ($conditions != '')
            $query .= 'WHERE '.$conditions.' ';

        if ($sortBy != '')
            $query .= 'ORDER BY '.$sortBy.' '.$sort.' ';

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
		$i = 0;
		$numRows = count($rows);
        while ($i != $numRows)
        {
			$row = $rows[$i];
			
            $object = new $className();
            $object->columns = $columns;		
            foreach($columns as $column)
            {
                $object->id = $row['id'];
                $object->record[$column] = $row[$column];
            }

            $objects[] = $object;
			
			++$i;
        }

        return $objects;
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
			foreach ($relationType as $relationClassName => $relation)
			{
				$thisColumnName = self::columnNameGenerator($this->objectInfo['tableName']);
				
				$relationTemplate = new $relationClassName();
				if ( !empty($relationTemplate->errors) )
				{
					$error  = 'createRelational(): Cannot continue with relation ';
					$error .= '\''.$relationClassName.'\' the relation does not construct without error, ';
					$error .= 'check it\'s configuration! The following error is the relation\'s error:';
					$this->errors[] = $error;
					$this->errors[] = $relationTemplate->errors[0];
					return false;
				}
				
				$relationColumnName = self::columnNameGenerator($relationTemplate->objectInfo['tableName']);

				$query  = 'CREATE TABLE IF NOT EXISTS `'.$relation['relationTableName'].'` ( ';
				$query .= $thisColumnName.' INT(10) unsigned NOT NULL, ';
				$query .= $relationColumnName.' INT(10) unsigned NOT NULL, ';
				$query .= 'PRIMARY KEY(`'.$thisColumnName.'`,`'.$relationColumnName.'`) ';
				$query .= ')';

				$database->execQuery($query);
			}
		}
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
}

?>
