<?php

class OrmDb{
	var $dbconn;
	
	function OrmDb($connectionString) {
		$this->dbconn = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
	}


	function select($tableName){
		return new OrmQuery($this, $tableName);
	}

	function insert($tableName){
		return new OrmCommand($this, $tableName, 'INSERT');
	}

	function update($tableName){
		return new OrmCommand($this, $tableName, 'UPDATE');
	}

	function delete($tableName){
		return new OrmCommand($this, $tableName, 'DELETE');
	}


	function query($query){
		$query = pg_query($this->dbconn,$query);
		if ($query === false){
			return false;
		}
		return new OrmResult($query);
	}



	function lastError(){
		return pg_last_error($this->dbconn);
	}
}

class OrmQuery{
	var $orm_db;

	var $table;
	var $where;
	var $whereString;
	var $order;
	var $columns;
	var $limit;
	var $join;
	var $union;

	var $queryArray;

	function OrmQuery($_orm_db, $table) {
		$this->table = null;
		$this->where = array();
		$this->order = array();
		$this->columns = array();
		$this->limit = null;	
		$this->join = array();
		$this->union = array();


		$this->orm_db = $_orm_db;
		$this->table = $table;

		$this->tableArrays = array();

	}

	function where($_where, $_eval, $_val){
		if (is_string($_where) && !is_null($_eval) && !is_null($_val)){
			$_where = array($_where,$_eval,$_val);
		}

		if (is_string($_where)){
			$this->whereString[] = $_where;
		}
		if (is_array($_where)){
			$this->where[] = $_where;
		}

		return $this;
	}

	function columns($_columns){
		$this->columns = $_columns;
		return $this;
	}

	function limit($_limit){
		$this->limit = $_limit;
	}

	function join($table, $on1, $on2 = null){
		if ($on2 == null){
			$on2 = $on1;
		}
		$this->join[$table] = array($on1,$on2);
	}

	//This can either take a table string name, in which case all the same where and column modifiers are applied,
	//Or, it can take a whole seperate $db->select() return as a seperate table. 	
	function union($table){
		$this->union[] = $table;	
		return $this;	
	}

	function tableArray($tableName = null){
		$primaryTable = false;
		if (is_null($tableName)){
			$tableName = $this->table;
			$primaryTable = true;
		}

		$queryParts = array();

		if (count($this->columns) <= 0){
			$queryParts['SELECT'] = "*";
		}
		else{
			$queryParts['SELECT'] = $this->columns;
		}

		$queryParts['FROM'] = $tableName;

		foreach ($this->join as $table => $keys) {
			$queryParts['JOIN'][] = $table." on (".$tableName.".".$keys[0]." = ".$table.".".$keys[0].")";
		}

		foreach ($this->where as $whereArray) {
			$queryParts['WHERE'][] = "$tableName.$whereArray[0] $whereArray[1] $whereArray[2]"; 
		}

		if (count($this->whereString) > 0){
			
			$queryParts['WHERE'][] = $this->whereString;
		}

		if ($primaryTable){
			foreach ($this->union as $table) {

				if (is_string($table)){
					$unionTableArray = $this->tableArray($table);
				}
				else{
					$unionTableArray = $table->tableArray($table->table); // provide the table name to ensure it is seen as a non-primary table
				}

				$queryParts['UNION'][] = $unionTableArray; 
			}		


			if ($this->limit != null){ // only the top level of the query (the primary table) should have a limit 
				$queryParts['LIMIT'] = $this->limit;
			}
		}


		return $queryParts;
	}

	function buildString($tableArray){
		$stringBuilder = array();
		$stringBuilder[] = 'SELECT';
		if(is_array($tableArray['SELECT'])){
			$stringBuilder[] =  implode(',', $tableArray['SELECT']);	
		}
		else{
			$stringBuilder[] =  $tableArray['SELECT'];		
		}

		$stringBuilder[] = 'FROM';
		$stringBuilder[] = $tableArray['FROM'];

		if (isset($tableArray['JOIN'])){
			foreach ($tableArray['JOIN'] as $joinstring) {
				$stringBuilder[] = 'INNER JOIN';
				$stringBuilder[] = $joinstring;
			}
		}

		if (isset($tableArray['WHERE'])){
			$stringBuilder[] = 'WHERE';
			$stringBuilder[] = '(' . implode(') AND (', $tableArray['WHERE']) . ')';
		}

		if (isset($tableArray['UNION'])){
			foreach ($tableArray['UNION'] as $unionTable) {
				$stringBuilder[] = 'UNION';
				$stringBuilder[] = $this->buildString($unionTable);
			}
		}

		if (isset($tableArray['LIMIT'])){
			$stringBuilder[] = 'LIMIT';
			$stringBuilder[] = $tableArray['LIMIT'];
		}

		return implode(' ', $stringBuilder);
	}

	function queryString(){
		$tableStrings = array();

		$mainTableArray = $this->tableArray();
		$this->queryArray = $mainTableArray; // storing this for error reporting
		$tableStrings[] = $this->buildString($mainTableArray);
		
		$this->queryArray = $mainTableArray; // storing this for error reporting

		return implode(' ', $tableStrings);
	}

	function count(){
		$result = $this->get();

		return $result->count();
	}

	function get(){
		$queryString = $this->queryString();

		$result = $this->orm_db->query($queryString);

		if ($result === false){
			echo 'SQL ERROR <br/>';
			echo $queryString . '<br/>';
			echo $this->orm_db->lastError().'<br/>';
			json_print($this->queryArray);
		}

		return $result;
	}

	function all(){
		$result = $this->get();
		return $result->all();
	}

	function first($default = null){
		$limit = 1;
		$result = $this->get();

		if ($result->count() > 0){
			return $result->row();
		}

		return $default;
	}
}

class OrmResult{
	var $query;

	function OrmResult($_query){
		$this->query = $_query;
	}

	function count(){
		return pg_num_rows($this->query);
	}

	function row(){
		return pg_fetch_array($this->query,NULL,PGSQL_ASSOC);
	}

	function all(){
		return pg_fetch_all($this->query);
	}
}

class OrmCommand{
	var $orm_db;
	var $table;
	var $type;
	var $values;
	var $where;

	var $queryString;

	function OrmCommand($_orm_db, $_table, $_type){
		$this->values = array();
		$this->where = array();

		$this->orm_db = $_orm_db;
		$this->table = $_table;
		$this->type = $_type;
	}

	function value($key, $value){
		$this->values[$key] = $value;
	}

	function where($where, $eval = null, $val = null){
		if (is_null($eval)){
			$this->where[] = $where;
		}
		else{
			$this->where[] = "$where $eval '$val'";
		}
	}

	function buildString(){
		$stringBuilder = array();

		$stringBuilder[] = $this->type;

		switch($this->type){
			case 'INSERT':
				$stringBuilder[] = 'INTO';
				$stringBuilder[] = $this->table;

				$names = array();
				$values = array();

				foreach ($this->values as $key => $value) {
					$names[] = $key;
					$values[] = $value;
				}

				$stringBuilder[] = '(' . implode(',', $names) .')';

				$stringBuilder[] = 'VALUES';

				$stringBuilder[] = "('" . implode("','", $values) ."')";

				break;

			case 'UPDATE':
				$stringBuilder[] = $this->table;
				$stringBuilder[] = 'SET';

				$valueSet = array();
				foreach ($this->values as $key => $value) {
					$valueSet[] = "$key = '$value'";
				}

				$stringBuilder[] = implode(',', $valueSet);

				if(count($this->where) > 0){
					$stringBuilder[] = 'WHERE';
					$stringBuilder[] = implode('AND',$this->where);
				}

				break;

			case 'DELETE':
				$stringBuilder[] = 'FROM';
				$stringBuilder[] = $this->table;

				if(count($this->where) > 0){
					$stringBuilder[] = 'WHERE';
					$stringBuilder[] = implode('AND',$this->where);
				}
		}

		$string = implode(' ', $stringBuilder);

		return $string;
	}

	function execute(){
		$string = $this->buildString();
		$this->queryString = $string;

		$result = $this->orm_db->query($string);
		
		return $result;
	}
}

?>