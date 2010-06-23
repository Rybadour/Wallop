<?php
/*
 * This file is part of Wallop.
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

class WallopDatabase
{
    public $connection;
    public $result;
    public $error;

	/* No need to comment this, just simple initialization
	 */
    public function WallopDatabase()
    {
        $this->connection = false;
        $this->result = false;
    }

	/* Calling this function
	 */
    public function connect()
    {
        global $_WALLOP;
        $dbData = $_WALLOP['mysql'];
		
		$dsn = 'mysql:dbname='.$dbData['database'] .';host='. $dbData['host'] ;
		$dbh = new PDO($dsn, $dbData['user'], $dbData['password']);	
		$this->connection = $dbh;
        
		if (!$this->connection)
        {
            $this->error = 'Unable to connect';
            return false;
        }
        return true;
    }
    
	/* Basically just a variadic function that wraps execQueryWith
	 */
    public function execQuery($format)
    {
        $num = func_num_args();
        $args = array_slice(func_get_args(), 1, $num - 1);

        return $this->execQueryWith($format, $args);
    }

    /* This function gets a query format and uses's the PDO::prepare function to sanytize it
	 */
    public function execQueryWith($format, $args)
    {
        if (!$this->connection)
        {
            $this->error = 'Not connected';
            return false;
        }

        $this->result = null;

        $arr[0] = $this->connection->prepare($format);
        if(!$arr[0])
        {
			$this->error  = "There was an error with your query's syntax: \n";
			$this->error .= join(",\n", $this->connection->errorInfo()) .".\n";
			return false;
        }

        try
		{
			$arr[0]->setFetchMode(PDO::FETCH_ASSOC);
			if($arr[0]->execute($args))
			{
		        $this->result = $arr[0];
		    }
            else
			{
				$this->error  = "There was an error with your query's syntax: \n";
				$this->error .= join(",\n", $arr[0]->errorInfo()) .".\n";
				return false;
			}
		}
		catch (PDOException $e)
		{ 
		    // catches the exeption and returns the error info array to the user
			$this->error  = "Unable to execute query\n";
			$this->error .= join(",\n", $arr).".\n";
			$this->error .= join(",\n", $arr[0]->errorInfo()) .".\n";
			return false;
		}
        
		// Return the id of the record just inserted
		if($this->connection->lastInsertId())
            return $this->connection->lastInsertId();
        else
            return true;
    }

	/* Retrieves the next record from the result set
	 */
    public function getRow()
    {
        if (!$this->connection)
        {
            $this->error = 'Not connected';
            return false;
        }
        if (!$this->result)
        {
            $this->error = 'No result set';
            return false;
        }

		try
		{
			$row = $this->result->fetch();
		}
		catch (PDOException $e)
		{ 
			$this->error = 'Unable to fetch row';
            return false;
		}

        return $row;
    }

	/* Returns all the records from the result set
	 */
    public function getAllRows()
    {
        $result = array();
        if (!$this->connection)
        {
            $this->error = 'Not connected';
            return false;
        }
        if (!$this->result)
        {
            $this->error = 'No result set';
            return false;
        }

		try
		{
			$result = $this->result->fetchAll();
		}
		catch (PDOException $e)
		{ 
			$this->error = 'Unable to fetch rows';
            return false;
		}
		
        return $result;
    }
	
    /* If a query failed, it will abort echoing the last error
	 */
    public function abort()
    {
		echo '<br /><p>Database Error:<br />';
		echo $this->error;
		echo '</p><br />';
    }

    /* Destory the database connection
	 */
    public function close()
    {
        //unseting the object to close it
        unset($this->connection);
		$this->connection = false;
    }

	/* A bit unnecessary at the moment but it might come in handy later
	 */
    public function __destruct()
    {
        $this->close();
    }
}
?>
