<?php
/*
  irp_extxArduino - Example for irp_classes (https://github.com/msillano/irp_classes)
  Copyright (c) 2017 Marco Sillano.  All right reserved.

  This library is free software; you can redistribute it and/or
  modify it under the terms of the GNU Lesser General Public
  License as published by the Free Software Foundation; either
  version 2.1 of the License, or (at your option) any later version.

  This library is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
  Lesser General Public License for more details.

  You should have received a copy of the GNU Lesser General Public
  License along with this library; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
// using 'PHP Serial extension' free: TX/RX to/from Arduino in php
// see  arduino/rawRxTx02.ino
$d = dirname(__FILE__);
require_once("$d/../upt_fifo/upt_fifo.php");
require_once("$d/../remoteDB/irp_commonSQL.php");

function txArduino($raw)
  {
   pushSETrequest(1, $raw);
   }
//  receive RAW fron Arduino
function rxArduino($protocol=NULL)
  {
 $id = NULL;
 $id =  pushGETrequest(1);
 sleep(5);
 $status = sqlValue ("SELECT status FROM fifo WHERE id = $id ");
 $timeout = 0;
 while (($status != 'READY')) {
   if ($timeout++ > 20) return "*** ERROR timeout";
   sleep(1);
   $status = sqlValue ("SELECT status FROM fifo WHERE id = $id ");
   }
 return popGETrequest($id);
}

?>
 
  
 