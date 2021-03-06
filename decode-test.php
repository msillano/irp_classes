﻿<?php
/*
  decode-tast-php - Example for irp_classes (https://github.com/msillano/irp_classes)
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

$d = dirname(__FILE__);
include("$d/irp_classes.php");
include("$d/irp_rxtxArduino.php");
// 'CAPTURE': requires Arduino and 'PHP Serial extension' free ver.(http://www.thebyteworks.com) to get RAW data. 
// The free serial communication fails after 1024 bytes... you must restart the server before any test! 
// But it is ok for demo purposes. 
// =================================================================================================
$protocol = 'JVC';               // default: change here to use this page alone
if (isset($_GET['protocol']))    // uses parameter from index.php
  {
    $protocol = $_GET['protocol'];
  }
$dataRAW = $protocol . '_RAW';
if (isset($_GET['captured']))    // HW capture
  {
    $dataRAW = 'CAPTURE_RAW';
  }
//----------------------- some IRP protocols and RAW data examples for test:
$JVC                         = '{38k,527}<1,-1|1,-3>(16,-8,(D:8,F:8,1,-45)+)';
$JVC_RAW                     = '8422|-4234|506|-1602|506|-1602|510|-546|506|-550|506|-550|502|-1606|506|-546|506|-1602|510|-1602|506|-1602|506|-546|506|-550|506|-550|506|-1602|506|-550|502|-550|506|-1000'; //  captured data
//'8374|-4282|462|-1646|466|-1642|466|-586|466|-590|466|-590|466|-1642|466|-590|462|-1646|462|-590|466|-1646|462|-1646|462|-594|462|-590|466|-1642|466|-590|462|-590|466|-1000';   //  captured data
//'8370|-4286|430|-1678|434|-1674|434|-622|434|-622|434|-618|434|-622|434|-622|430|-1674|462|-622|406|-1674|434|-1678|430|-1678|434|-1674|434|-646|406|-1678|434|-622|430|-1000';  //  captured data
//'8400|-4200|525|-1575|525|-1575|525|-1575|525|-525|525|-525|525|-525|525|-525|525|-525|525|-1575|525|-1575|525|-1575|525|-1575|525|-1575|525|-1575|525|-525|525|-525|525|-23625'; 

$Denon                       = '{38k,264}<1,-3|1,-7>(D:5,F:8,0:2,1,-165,D:5,~F:8,3:2,1,-165)+';
$Denon_data                  = '{D=7,F=0x3F}';
$Denon_RAW                   = '264|-1848|264|-1848|264|-1848|264|-792|264|-792|264|-1848|264|-1848|264|-1848|264|-1848|264|-1848|264|-1848|264|-792|264|-792|264|-792|264|-792|264|-43560|264|-1848|264|-1848|264|-1848|264|-792|264|-792|264|-792|264|-792|264|-792|264|-792|264|-792|264|-792|264|-1848|264|-1848|264|-1848|264|-1848|264|-43560';

$RC6                         = '{36k,444,msb}<-1,1|1,-1>(6,-2,1:1,0:3,<-2,2|2,-2>(T:1),D:8,F:8,^107m)+';
$RC6_RAW                     = '2606|-942|390|-922|390|-490|390|-490|1298|-1354|390|-490|390|-490|390|-490|390|-486|390|-490|418|-462|418|-462|390|-490|386|-494|386|-490|834|-922|390|-490|390|-486|394|-490|386|-4374';

$NEC1                        = '{38.0k,564}<1,-1|1,-3>(16,-8,D:8,S:8,F:8,~F:8,1,^108m,(16,-4,1,^108m)*)';
$NEC1_RAW                    = '8914|-4570|486|-638|486|-638|486|-638|482|-642|486|-1758|486|-638|486|-1762|486|-638|482|-1762|486|-1762|486|-1762|486|-1762|514|-610|482|-1762|486|-638|486|-1762|482|-1766|486|-1758|486|-638|486|-638|486|-638|486|-638|486|-1762|482|-642|482|-638|486|-638|486|-1762|482|-1762|462|-1786|486|-1762|486|-638|486|-1762|482|-1000'; // captured data (really are NEC1_16, used with NEC1)

$NEC1_16                     = '{38.0k,562}<1,-1|1,-3>(16,-8,D:8,~D:8,F:8,~F:8,1,^110m,(16,-4,1,^110m)*)';
$NEC1_16_RAW                 = '9006|-4498|562|-562|558|-566|558|-566|558|-566|558|-1690|558|-566|558|-1690|554|-566|558|-1694|554|-1690|558|-1690|558|-1686|558|-566|558|-1690|558|-566|558|-1690|534|-1714|530|-594|554|-1690|534|-1714|534|-1714|530|-594|530|-594|530|-594|530|-594|530|-1714|534|-590|530|-594|534|-590|530|-1718|530|-1714|530|-1718|530|-7000'; // captured data
//'8934|-4570|486|-638|486|-638|482|-642|486|-638|482|-1762|490|-634|486|-1762|486|-638|486|-1758|462|-1786|486|-1762|486|-1762|486|-634|486|-1762|486|-638|486|-1762|482|-638|486|-1762|486|-638|486|-638|482|-1766|482|-638|486|-638|486|-638|482|-1766|482|-642|486|-1762|482|-1762|462|-662|486|-1758|462|-1786|486|-1762|486|-1000';    // captured data 

$NEC2                        = '{38.0k,564}<1,-1|1,-3>(16,-8,D:8,S:8,F:8,~F:8,1,^108m)+';
$NEC2_data                   = '{D=0,S=191,F=1}';
$NEC2_RAW                    = '9024|-4512|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-1692|564|-1692|564|-1692|564|-1692|564|-1692|564|-1692|564|-564|564|-1692|564|-1692|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-564|564|-1692|564|-1692|564|-1692|564|-1692|564|-1692|564|-1692|564|-1692|564|-40884';
//'{38.0,282,69}32|-16|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-6|2|-6|2|-6|2|-6|2|-6|2|-6|2|-2|2|-6|2|-6|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-2|2|-6|2|-6|2|-6|2|-6|2|-6|2|-6|2|-6|2|-127|-18';  // test using compressed data 

$AdNotam                     = '{35.7K,895,msb}<1,-1|1,-3>(0:1,1:1,D:6,F:6,^114m)+';
$AdNotam_data                = '{D=0x17,F=0x15}';
$AdNotam_RAW                 = '895|-895|895|-2685|895|-895|895|-2685|895|-895|895|-2685|895|-2685|895|-2685|895|-895|895|-2685|895|-895|895|-2685|895|-895|895|-2685|-74620';

$Amino                       = '{36.0k,268,msb}<-1,1|1,-1>([T=1] [T=0],7,-6,3,D:4,1:1,T:1,1:2,0:8,F:8,15:4,C:4,-79m)+{C =(D:4+4*T+9+F:4+F:4:4+15)&15}';
$Amino_data                  = '{D=7,F=0x3F}';
$Amino_RAW                   = '1876|-1608|804|-268|536|-268|268|-268|268|-268|268|-268|268|-536|536|-536|268|-268|268|-268|268|-268|268|-268|268|-268|268|-268|268|-268|536|-268|268|-268|268|-268|268|-536|268|-268|268|-268|268|-268|536|-268|268|-268|268|-268|268|-536|268|-268|536|-536|268|-79000';
//'{36.0,89,64}21|-18|9|-3|6|-3|3|-3|3|-3|3|-3|3|-6|6|-6|3|-3|3|-3|3|-3|3|-3|3|-3|3|-3|3|-3|6|-3|3|-3|3|-3|3|-6|3|-3|3|-3|3|-3|6|-3|3|-3|3|-3|3|-6|3|-3|6|-6|3|-127|-127|-127|-127|-127|-127|-126';    // test using compressed data
$Archer                      = '{0k,12}<1,-3.3m|1,-4.7m>(F:5,1,-9.7m)+';
$Archer_data                 = '{F=17}';
$Archer_RAW                  = '12|-4700|12|-3300|12|-3300|12|-3300|12|-4700|12|-9700';

$DirectTV                    = '{38k,600,msb}<1,-1|1,-2|2,-1|2,-2>(5,(5,-2,D:4,F:8,C:4,1,-50)+) {C=7*(F:2:6)+5*(F:2:4)+3*(F:2:2)+(F:2)}';
$DirectTV_data               = '{D=0x0A, F=37}';
$DirectTV_RAW                = '6000|-1200|1200|-600|1200|-600|600|-600|1200|-600|600|-1200|600|-1200|1200|-1200|1200|-600|600|-30000';

$Grunding16                  = '{35.7k,578,msb}<-4,2|-3,1,-1,1|-2,1,-2,1|-1,1,-3,1> (806u,-2960u,1346u,T:1,F:8,D:7,-100)+';
$Grunding16_data             = '{T=0, D=0x3A, F=37}';
$Grunding16_RAW              = '806|-2960|1346|-2312|1156|-1734|578|-578|578|-2312|1156|-1156|578|-1156|578|-1156|578|-1156|578|-578|578|-1734|578|-1156|578|-1156|578|-1156|578|-1156|578|-57800';

$Nokia                       = '{36k,msb}<164,-276|164,-445|164,-614|164,-783>(412,-276,D:8,S:8,F:8,164,-10m)+';
$Nokia_data                  = '{ D=0xDD, S=0x4A, F=37}';
$Nokia_RAW                   = '412|-276|164|-783|164|-445|164|-783|164|-445|164|-445|164|-276|164|-614|164|-614|164|-276|164|-614|164|-445|164|-445|164|-10000';

$OrtekMCE                    = '{38.6k,480}<1,-1|-1,1>([P=0][P=1][P=2],4,-1,D:5,P:2,F:6,C:4,-48m)+{C=3+#D+#P+#F}';
$OrtekMCE_data               = '{D=0x3A, F=37}';
$OrtekMCE_RAW                = '1920|-480|480|-960|960|-960|480|-480|960|-480|480|-960|960|-960|960|-480|480|-960|480|-480|960|-480|480|-960|480|-48000';

$XMP                         = '{38k,136,msb}<210u,-760u>(<0:1|0:1,-1|0:1,-2|0:1,-3|0:1,-4|0:1,-5|0:1,-6|0:1,-7|0:1,-8|0:1,-9|0:1,-10|0:1,-11|0:1,-12|0:1,-13|0:1,-14|0:1,-15>(T=0,(S:4:4,C1:4,S:4,15:4,OEM:8,D:8,210u,-13.8m,S:4:4,C2:4,T:4,S:4,F:16,210u,-80.4m,T=8)+)){C1=-(15+S+S::4+15+OEM+OEM::4+D+D::4):4,C2=-(15+S+S:4+T+F+F::4+F::8+F::12)&15}';
$XMP_data                    = '{D=0x3A, S=0x33, F=0xFEDC, OEM=0xFe}';
$XMP_RAW                     = '210|-1168|210|-2392|210|-1168|210|-2800|210|-2800|210|-2664|210|-1168|210|-2120|210|-13800|210|-1168|210|-2256|210|-760|210|-1168|210|-2800|210|-2664|210|-2528|210|-2392|210|-80400';

$Fujitsu_Aircon              = '{38.4k,413}<1,-1|1,-3>(8,-4,20:8,99:8,0:8,16:8,16:8,254:8,9:8,48:8,H:8,J:8,K:8,L:8,M:8,N:8,32:8,Z:8,1,-104.3m)+ {H=16*A + wOn, J=16*C + B, K=16*E:4 + D:4, L=tOff:8, M=tOff:3:8+fOff*8+16*tOn:4, N=tOn:7:8+128*fOn,Z=256-(H+J+K+L+M+N+80)%256}';
// [A:0..15,wOn:0..1,B:0..15, C:0..15,D:0..15,E:0..15,tOff:0..1024,tOn:0..1024,fOff:0..1,fOn:0..1]
$Fujitsu_Aircon_data         = '{A=0,wOn=1,B=2, C=3,D=4,E=5,tOff=0x10,tOn=0x20,fOff=0,fOn=0}';
$Fujitsu_Aircon_RAW          = '3304|-1652|413|-413|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-1239|413|-1239|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-104300';
//
// NOTA: We can add to existing expressions, calculating H,J,K,L,M,N and Z from A,wOn,B,C,D,E,tOff,tOn,fOff,fOn, 
// also the inverses expressions, calculating  A,wOn,B,C,D,E,tOff,tOn,fOff,fOn from H,J,K,L,M,N,Z.
// This will not influence the ENCODE phase (values have precedence on expressions) but in DECODE phase we can get
// from RAW not only H,J,K,L,M,N,Z  but also  A,wOn,B,C,D,E,tOff,tOn,fOff,fOn. (using dataVerify() function)
$Fujitsu_Aircon_modified     = '{38.4k,413}<1,-1|1,-3>(8,-4,20:8,99:8,0:8,16:8,16:8,254:8,9:8,48:8,H:8,J:8, K:8, L:8, M:8,N:8,32:8,Z:8,1,-104.3m)+ {H=16*A + wOn, J=16*C + B, K=16*E:4 + D:4, L=tOff:8, M=tOff:3:8+fOff*8+16*tOn:4, N=tOn:7:4+128*fOn,Z=256-(H+J+K+L+M+N+80)%256, A=H:4:4,wOn=H:1,B=J:4,C=J:4:4,D=K:4,E=K:4:4,tOff=L+256*M:3, tOn=M:4:4+16*N:7,fOn=N:1:7,fOff=M:1:3}';
$Fujitsu_Aircon_modified_RAW = '3304|-1652|413|-413|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-1239|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-1239|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-413|413|-1239|413|-413|413|-413|413|-1239|413|-1239|413|-1239|413|-413|413|-1239|413|-413|413|-413|413|-413|413|-104300';
// ---------------------------
echo '<HTML><HEAD></HEAD><BODY>';
function rawMicros($raw)
  {
    // excludes first and last times
    $sum   = 0;
    $times = explode('|', $raw);
    for ($k = 4; $k < count($times) - 8; $k++) // see irp_classes @572 $skipfirst, $skiplast 
        $sum += abs($times[$k]);
    return $sum;
  }
// ============================================ test code ==================
echo "<b>==== DECODING IR PROTOCOL <i>$protocol</i> ====</b><br><br>";
// --------------------  receiving data
if (isset($_GET['captured']))
  {
    $CAPTURE_RAW = rxArduino();
    echo 'CAPTURED RAW = {' . $CAPTURE_RAW . '}<br>';
    echo '+++ from Serial: ' . ser_version() . '<br>----------------<br>';
    //   ===============  end serial arduino
  }
// ----------------------------------- processing data
echo " ==== PROTOCOL INFOS by toString() ====<br>";
$aProtocol = new irp_protocol($$protocol);
$aProtocol->toString(); // print protocol infos
$rawd = $aProtocol->decodeRaw($$dataRAW);
echo '<br>==== OUTPUT from decodeRaw(), output RAW:<br>';
print('DATA = ' . $rawd . '<br>');
echo '<br>==== OUTPUT from dataVerify(false):<br>';
echo '<pre>' . $aProtocol->dataVerify(false) . '</pre>';
echo '<br>==== OUTPUT from dataVerify(true) - verbose:<pre>';
echo $aProtocol->dataVerify(true) . '</pre>';
// decodes again the RAW data, but with BIN output
echo '<br>==== OUTPUT from decodeRaw(), output BIN: <br><pre>';
$aProtocol->setOutputBin();
$aProtocol->resetData(); // in case of permanence, restart
$bin = $aProtocol->decodeRaw($$dataRAW);
print('BIN   = ' . $bin . '<br>');
if ($bin[0] != '*')
  {
    echo '<br>==== The BIN output from decodeRaw(), modified by RAWprocess() : <br>';
    print('BIN-0 = ' . $aProtocol->RAWprocess($bin, 0) . '<br>');
    print('BIN-1 = ' . $aProtocol->RAWprocess($bin, 1) . '<br>');
    print('BIN-2 = ' . $aProtocol->RAWprocess($bin, 2) . '</pre>');
  }
// only for real captured RAW data:
if (($protocol == 'JVC') || ($protocol == 'RC6') || ($protocol == 'NEC1') || ($protocol == 'NEC1_16') || isset($_GET['captured']))
  {
    echo '<br>==== Verify RAW normalization with IRP: <pre>';
    echo 'CAPTURED   = [' . rawMicros($$dataRAW) . '] ' . $$dataRAW . '<br>';
    //
    $RAWn = $aProtocol->getNormRAW();
    echo 'NORMALIZED = [' . rawMicros($RAWn) . '] ' . $RAWn . '<br>';
    //
    $aProtocol->setOutputRaw();
    $aProtocol->resetData(); // in case of permanence, restart
    $RAWe  = $aProtocol->encodeRaw($rawd, 1);
    $RAWe0 = $aProtocol->RAWprocess($RAWe, 0);
    echo 'EXPECTED   = [' . rawMicros($RAWe0) . '] ' . $RAWe0 . '<br>';
    print('RAW-1        = ' . $aProtocol->RAWprocess($RAWn, 1) . '<br></pre>');
  }
// test raw
if (($protocol == 'JVC') || ($protocol == 'RC6') || ($protocol == 'NEC1') || ($protocol == 'NEC1_16') || isset($_GET['captured']))
  {
    echo '<br>==== Verify RAW normalization without IRP: <pre>';
    $rawn2 = $aProtocol->RAWnormalize($$dataRAW);
    echo 'NORMALIZED no IRP  = ' . $rawn2 . '<br>';
    //
    print('RAW-1 no IRP = ' . $aProtocol->RAWprocess($rawn2, 1, 37) . '<br></pre>');
  }
echo '<br>';
echo ' <hr> <center><<<  <a  href="javascript:history.go(-1)" >Back</a>	</center>';
echo '</BODY></HTML>';
?>