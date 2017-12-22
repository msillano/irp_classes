<?php
$d = dirname(__FILE__);
require_once("$d/../upt_fifo/upt_fifo.php");
require_once("$d/../remoteDB/irp_commonSQL.php");



$protocol = $_GET['protocol'] ;
$remote = $_GET['remote'] ;
$code = $_GET['code'] ;
$key = $_GET['key'] ;
$mode = $_GET['mode'] ;

$id = $_GET['id'];
$status = sqlValue ("SELECT status FROM fifo WHERE id = $id ");
if ($status == 'READY'){
    movePage(100,"usr_simplerawRX.php?protocol=$protocol&id=$id&remote=$remote&code=$code&key=$key&mode=$mode");    
  }

echo '<HTML><HEAD><meta http-equiv="refresh" content="3"></HEAD><BODY>';
echo " <h2> STATUS $status <h2>";
echo "<form action= 'index.html'> <input type = 'submit' value='ABORT'></form>";
echo '</BODY></HTML>';

?>