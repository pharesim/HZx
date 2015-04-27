<?php
class Sqlite extends SQLite3
{

/**
 * select from database and return as array
 * 
 * @param string $query escaped sql query
 * 
 * @return array result
 */
	public function fromdb($query)
	{
		$result = $this->query($query);
		$rows = [];
		while($row = $result->fetchArray(SQLITE3_ASSOC))
		{
			$rows[] = $row;
		}

		return $rows;
	}


/**
 * save to db
 * 
 * @param array $query query to send
 * 
 * @return boolean executed or not
 */
	public function todb($query)
	{
		$executed = false;
		while(!$executed)
		{
			if($this->exec($query))
			{
				return true;
			}	

			sleep(1);
		}

		return false;
	}


/**
 * escape for sql query
 * 
 * @param string $string string to escape
 * 
 * @return string escaped
 */
	public function escape($string)
	{
		$string = $this->escapeString($string);
		return $this->byType($string);
	}


	function save($table,$data)
	{
		$query = 'INSERT INTO '.$table.' (';
		$values = '(';
		foreach($data as $key=>$value)
		{
			$query  .= $key.',';
			$values .= $this->escape($value).',';
		}
		$query  = substr($query,0,-1);
		$values = substr($values,0,-1);
		$query .= ') VALUES '.$values.');';

		return $this->todb($query);
	}

	function update($table,$data,$conditions=array())
	{
		$query = 'UPDATE '.$table.' SET ';
		foreach($data as $key=>$value)
		{
			$query .= $key."=".$this->escape($value).",";
		}
		$query = substr($query,0,-1);

		if(!empty($conditions))
		{
			$query .= ' WHERE ';
			foreach($conditions as $key=>$value)
			{
				if($key == 'OR' || $key == 'AND')
				{
					$query .= '(';
					foreach($value as $k=>$v)
					{
						$query .= $k."=".$this->escape($v)." ".$key.' ';
					}

					$l = -(strlen($key)+2);
					$query = substr($query,0,$l).')';
				}

				else
				{
					$query .= $key."=".$this->escape($value)." AND ";
				}

				$query = substr($query,0,-5);
			}
		}
		
		return $this->todb($query.';');
	}

	public function byType($data)
	{
		if($data !== 0 && $data !== 1)
		{
			$tmp = explode('.', $data);
			if(!is_numeric($data) || strlen($tmp[0]) > 9)
			{
				$data = "'".$data."'";
			}
		}

		return $data;
	}


}