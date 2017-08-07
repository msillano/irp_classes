<?php
// using 'PHP Serial extension' free: TX/RX to/from Arduino in php
// see  arduino/rawRxTx02.ino
function txArduino($raw)
  {
    $arduinocom = 'COM3'; // TODO: change here if required
    if (!ser_isopen())
        ser_open($arduinocom, 9600, 8, "None", "1", "None");
    //
    if (!ser_isopen())
      {
        echo "<div class=Error>";
        echo "ERROR: '.$arduinocom.' not open ";
        echo "</div>";
        exit;
      }
    echo '+++ from Serial: ' . ser_version() . '<br>';  // to verify 1000 bytes limits
    sleep(2);
    $fromarduino = '';
    // wait an 'A' from Arduino
    do
      {
        sleep(2);
        $fromarduino = ser_read(); // handshake
      } while (strpos($fromarduino, 'A') === false);
    //  echo 'start send <br>';
    // then send 'T' + data
    ser_write('T'); // command: T 
    sleep(1);
    ser_write($raw); // send data
  }
//  receive RAW fron Arduino
function rxArduino()
  {
    $arduinocom = 'COM3'; // TODO: change here if required
    if (!ser_isopen())
        ser_open($arduinocom, 9600, 8, "None", "1", "None");
    //
    if (!ser_isopen())
      {
        echo "<div class=Error>";
        echo "ERROR: '.$arduinocom.' not open ";
        echo "</div>";
        exit;
      }
    echo '+++ from Serial: ' . ser_version() . '<br>';
    sleep(2);
    $fromarduino = '';
    $RXstatus    = 0;
    // RX finite state Automata
    while (true)
      {
        sleep(2);
        switch ($RXstatus)
        {
            case 0: // waiting  'A'
                $fromarduino = ser_read();
                //			  echo 'status 0, rx ='.$fromarduino.'<br>';
                if (strpos($fromarduino, 'A') === false)
                    break;
                $RXstatus = 1;
                break;
            case 1: // sends 'R' command
                ser_write('R');
                sleep(1);
                $fromarduino = ser_read();
                //			  echo 'status 1, rx ='.$fromarduino.'<br>';
                $fromarduino = '';
                sleep(2);
                $RXstatus = 2;
                break;
            case 2: //collect all data    
                $fromarduino .= ser_read();
                //			  echo 'status 2, rx ='.$fromarduino.'<br>';
                if (strpos($fromarduino, '}') !== false)
                  {
                    $RXstatus = 3; // end RAW
                    break;
                  }
                if (strpos($fromarduino, 'A') !== false)
                    $RXstatus = 0; // restart
                break;
            case 3: // done: format RAW and returns
                $s = strpos($fromarduino, '={'); // start RAW
                $e = strpos($fromarduino, '}'); // end RAW
                if ($e > $s)
                    return trim(substr($fromarduino, $s + 2, $e - $s - 2));
                $e = strrpos($fromarduino, '}');
                if ($e > $s)
                    return trim(substr($fromarduino, $s + 2, $e - $s - 2));
                $status = 0; // bad data, restart
        }
      }
  }
?>