<?php

include 'json.php';
include 'orm_library.php';


ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(-1);

$db = new OrmDb("host=localhost port=5432 dbname=manhattan2 user=pgsql password=nokia6210");

echo '########### Create, Update, Delete ########### <br/>';
$q = $db->insert('alerts');
$q->value('touser','me');
$q->execute();
echo $q->queryString . '<br/>';

$q = $db->update('alerts');
$q->value('message','hello');
$q->where('touser','=','me');
$q->execute();
echo $q->queryString . '<br/>';

$q = $db->delete('alerts');
$q->where('touser','=','me');
$q->execute();
echo $q->queryString . '<br/>';

echo '<br/><br/><br/>  ########### Simple Query ########### <br/>';
$q = $db->select("job");
$q->where('job_no','=','369012 ');
$job = $q->first();

json_print($q->queryArray);
json_print($job);

echo '<br/><br/><br/> ########### Complex Query ########### <br/>';
$q = $db->select("job");
$q->join('customer','customer_no');
$q->columns(array('job_no','customer_postcode'));
$q->union('job_archieve');
$q->limit(10);

$table = $q->all();

json_print($q->queryArray);// this usually won't get accessed, just for demo purposes
json_print($table);


?>