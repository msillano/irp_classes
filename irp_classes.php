<?php
/*
  irp_classes - This is an 'execution process' for IRPs, extended to encode and decode IR remote commands.
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
// This classes was designed for applications using IR remote control, like home control, and not for analysing or 
//   IR protocols reverse-engineering, because for that they are many better applications (IRremote, IrScrutinizer etc..).
// The scenario is: having some HW to receive/transmit IR (my favourite is an Arduino), we want an application to 
//   manage many devices in a room. We will use a DataBase to store informations about all devices and protocols,
//   and a WEB user interface: to control all devices we must implement a learning task with UI (if IRP missed) and
//   a sending task with UI. This design allows WiFi control (smartphone) and also remote control (Internet).
//   The HW for the web server (WAMP, LAMP...) can be as little as an Arduino Yun or a TVBOX.
//
// This class implements the core algorithms required for working with IR remote control, i.e. encode and decode 
//   IR commands. For that, you must known the IR Protocol (IRP: http://www.hifi-remote.com/wiki/index.php?title=IRP_Notation)
//   of your devices.  If you don't known the IRP, you still can store and send RAW streams.
// To store and retrieve IR commands we have many options:
//    RAW: big size but not requires IRP (like RAW-0 or the compressed versions: RAW-1,RAW-2 see full-test.php page)
//    HEX: small, but requires IRP (like BIN-1 or BIN-2  in full-test.php page)
//    DATA SET: smallest (like $JVC_data ='{D=7,F=0x3F}', see full-test.php).
// In some cases we can have 2 DATA set: the <device-parameters> and the <protocol-parameters>: this class can process both.
//      (see $Fujitsu_Aircon and $Fujitsu_Aircon_modified in full-test.php )
// My preferences are:
//    RAW-0 to receive from Arduino, because it requires less code.
//    RAW-1 to send to Arduino, because it is complete and minimizes RAM occupation on Arduino board.
//    If  <device-parameters> are knowns, I found more natural to store and work using it.
//    If  <device-parameters> are unknowns, i.e. if we must implement and use a 'learning' phase, and the 
//      reverse engineering of the IRP and <device-parameters> is too complex, then the simplest way is to use the 
//      RAW format (compressed: RAW-1).
// 
// Features
// a) Recursive implementation (uses 3 classes)
// b) Uses protocol definitions (valid IRP)
// c) Repetitions and dittos as defined in IRP, or required by user. 
// d) captured RAW stream can be dynamically normalized. 
// e) input/output in many formats (4 RAW and 4 BIN)
// f) optional data permanence (as required by some IRP)
// g) many test and debug functions
// h) normalization and compression of RAW stream without IRP
//
// ENCODE
//  - See method encodeRaw(value-set, repetitions)
//  - Requires a values-set and the number of repetitions to produce a RAW IRstream
//  - The <values-set> can be:
//          1) a php associative array, like: $data['D']=7; $data['F']=0x3F;
//          2) a string <protocol-parameters> or <device-parameters>, format like Expressions in IRP: '{D=7,F=0x3F}'
//          3) a HEX string, like: 'E0FC'. This string can be build by RAWprocess() using the BIN output of ENCODE/DECODE.
//  - Accepts integer values in decimal or hex or octal (php standards: 16 or 0x10 or 020). You must use '.' as decimal separator.
//    OUTPUT (see setOutputBin()/setOutputRaw()):
//       RAW: if $outMode = 'raw', output is the complete timing for an IRstream in microseconds (+ mark, - space), it 
//            includes repetitions and dittos. Ready to send (production) e.g.: '210|-760|210|-760|-1632|210|-760|-408'
//            RAW format is required to transmit IR commands using ad-hoc hardware (e.g. Arduino).
//       BIN: if $outMode = 'bin', output is a pure data bit-stream ('1' or '0'), 
//            without any fixed duration (no header, trailer etc.). eg:  '1110000011111100'
//            BIN format is for test and to get the 'HEX string' data-set format using RAWprocess().
//       In case of IRP errors, a '+++ WARNING....' message is send to output.
//  - This two basic outputs can be processed by RAWprocess() to get a total of 8 output formats.
//  - No size limitation: ok for air conditioner protocols, repetitions etc.
//
// DECODE
//  - see method decodeRaw(RAWdata)
//  - Requires a complete plain RAW IR signal using '|' as separator e.g.: '210|-760|210|-760|-1632|210|-760|-408'
//    or any RAW compressed format build by RAWprocess() (accepts RAW, RAW-0, RAW-1, RAW-2: see full-test.php)
//    (note: RAW-0 is like the signal coming from an IR receiver, RAW-1 is my favorite in applications)
//  - The input RAW stream can be processed 'as is' or it can be normalized before processing to get better results.
//    OUTPUT (see setOutputBin()/setOutputRaw()):
//      RAW: if $outMode = 'raw', output is <protocol-parameters>  as string (format like Expressions): '{D=7,F=0x3F}': 
//      BIN: if $outMode = 'bin', output is a data bit-stream ('1' or '0'), without any fixed duration: like ENCODE BIN.
//      In case of RAW data error, it returns an error message starting '*** ERROR...', and $this->isError() returns true.
//  - The BIN output can be processed by RAWprocess() to get HEX string
//  - After calling decodeRaw() you can call the dataVerify() method to process <device-parameters> (if any) and verify Expressions.
//  - No size limitation, decodes repeated complete steams.
//
// DECODE - NORMALIZATION
//   The hardware of the IR input device uses an integrator/smith-trigger. If the IR signal is low or with interferences
//   this circuit can under-estimate mark times (and over-estimate spaces). 
//   In Lib2 (basic Arduino IR RX/TX library) exists a fixed compensation (see DEFAULT_MARK_EXCESS in IRLibRecvBase.h) 
//   but for better decodes here I use a method, arrayNormalize(), that does a dynamic normalization of the raw signals against 
//   a (note) IRP. The irp_classes uses it by default, but the user can change this behaviour using setNormalize(false).
//   Also the method RAWnormalize(RAWdata) does a normalization of <RAWdata>, but without IRP. 
//   It can fail with some unusual protocols, in this case the output is equal to input. (see decode_test.php)
//
// MORE METHODS
//    - setOutputRaw() option: output is a RAW stream (encode) or a <protocol-parameters> string (decode). Default.
//    - setOutputBin() option: output is a BIT stream (use it before encode and decode)
//    - setNormalize(true|false) option: if true decodeRaw() does a normalization in RAW input. Default true.
//    - setDataPermanence() option: set true doDataPermanence. Default false. (before encode and decode)
//    - resetData(), to clean data in DataPermanence mode, e.g. before a re-run.
//    - dataVerify(true|false)  method to test decode results, in verbose (true) or terse modes (after decode).
//    - getNormRAW()   to get the normalized RAW stream (after decode, for test and debug).
//    - toString()     infos on IRP (after constructor, for test and debug).
//  next methods don't uses IRP, so they can be used also to handle RAW stream from unknown protocols:
//    - RAWnormalize(RAWdata) Try Normalization of a plain RAW stream (accepts RAW, RAW-0).
//    - RAWprocess(RAWdata, 0|1|2, [frequence]) to process RAW (from HW or encode) or BIN (from encode/decode) streams.
//      If the parameter 'frequence' is absent or NULL it uses IRP data, else it don't uses IRP.
//  errors:
//    - isError()  returns true in case of decoding errors. (also: test the decode output char[0] != '*')
//    - getNormError() returns false or normalization error message (Normalize fails silent, original RAW stream is processed)
//
// MORE FUNCTIONS     
//    - irp_getRMatchBrace($text, $braceOpen, $pos, $braceClose)
//    - irp_getLMatchBrace($text, $braceOpen, $pos, $braceClose)
//    - irp_onion($text, $lbrace, $rbrace)
//    - irp_explodeVerify($result)
//    - irp_explodeRAW1($raw1)
//    - irp_implodeRAW1($rawArray)
// 
// Limits in this implementation
// 1) Do not accepts ? or ?? or ??? in IRP
// 2) Many different errors in case of not well formed IRP or bad values-set (limited diagnostic)
// 3) In case of many commands in a raw stream, decodeRaw() returns last results in RAW mode, all results in BIN mode.
// 4) dataVerify() requires a well formed IRP, read comments in code.
// 5) Data permanence is implemented saving data in a file. 
// 6) RAWnormalize() (without IRP) can fails with some protocols (better if min_mark_length == min_space_length)
// 7) This is an experimental version, so many 'echo' for debug are still in place but commented.
//
// Extensions to standard IRP, as defined in http://www.hifi-remote.com/wiki/index.php?title=IRP_Notation:
// 1) Repeats: accepts )+; )*; )3; )2+; )X; )X+; single digit numbers, or single letter variable (extension), no expressions.
// 2) Accepts(bad IRP): {36k,268}<-1,1|1,-1>[T=1][T=0](7,-6,3,D:4,1:1,T:1,1:2,F:8,C:4,-79m)+ 
//    and processes it as: {36k,268}<-1,1|1,-1>([T=1][T=0],7,-6,3,D:4,1:1,T:1,1:2,F:8,C:4,-79m)+ 
//
// usage with IRP:
// step 1)  create an irp_protocol object using a valid IRP:
//    $aProtocol = new irp_protocol($IRP); 
// step 2)  call $aProtocol->setOutputBin() | $aProtocol->setOutputRaw() to set output style
// step 3a) call $aProtocol->encodeRaw() with a data-set, and optionally $aProtocol->RAWprocess() to filter result.
// step 3b) call $aProtocol->decodeRaw() with a RAW stream, and optionally $aProtocol->dataVerify() to get more infos.
// see full-test.php, decode-test.php (in dir phpIRPlib)
//
// usage without IRP:
// step 1)  create an irp_protocol object using NULL:
//    $aProtocol = new irp_protocol(NULL); 
// step 2a)  call $aProtocol->RAWnormalize($CAPTURE_RAW ) to normalise CAPTURED
// step 2b)  call $aProtocol->RAWprocess($NORMALISED_RAW, 1, 38) to compress it in RAW-1
//
// see also remoteDB (https://github.com/msillano/remoteDB) a MySQL application using irp_classes
// ---------------------------------------------------------------------------------------
define('IRP_RAW_WIDE', 0); // $rmode values for RAWprocess()
define('IRP_RAW_PACK', 1);
define('IRP_RAW_BYTE', 2);
//
define('SKIPFIRST', 2);    // to skip head and tail of RAW data
define('SKIPLAST', 1);

/*
 * Main class
 */
class irp_protocol
  {
    // config   
    // on/off to store data on file to get data permanence (It is required by some IRP)
    // note: if  $doDataPermanence = true the warnings:
    //  '+++ WARNING: Variable X not found!' and  
    //  '+++ WARNING: var X not set! (uses default 0)' 
    // are suppressed: it uses quiet the default 0.
    // note: changing protocols, data permanence can give bad results. Use resetData()
    // to clear all data in case of re-run or protocol change.
    public $doDataPermanence = false;
    // file to store permanent data    
    const DATAFILE = '/dataStore.txt'; // used with dirname(__FILE__)
    // --------------------------------------------------------------------      
    // internal use
    const CHAR_LIST = '|'; // RAW separator
    const CHAR_EXTRA = '§'; // internal use, any char not allowed in IRP
    // 
    const TIME_FACTOR_DELTA = 3; // div factor for decode  -  do not change
    const TIME_FACTOR_ERR = 6; // div factor for RAWprocess - do not change
    // 
    const OUT_RAW = 'raw'; //internal, - use:  $this->setOutputRaw()
    const OUT_BIN = 'bin'; //internal, - use:  $this->setOutputBin()
    //   
    public $BMASK = array(0, 0x000001, 0x000003, 0x000007, 0x00000F, 0x00001F, 0x00003F, 0x00007F, 0x0000FF, 0x0001FF, 0x0003FF, 0x0007FF, 0x000FFF, 0x001FFF, 0x003FFF, 0x007FFF, 0x00FFFF, 0x01FFFF, 0x03FFFF, 0x07FFFF, 0x0FFFFF, 0x1FFFFF, 0x3FFFFF, 0x7FFFFF, 0xFFFFFF); // 0..24 bit mask
    // ---- GeneralSpec IRP
    public $baseBSpec = null;
    public $frequence = 0;
    public $tBase = 1;
    public $order = 'lsb';
    public $IRP = '';
    private $isNull = false;
    //---- encode
    private $mode = 'decode'; // decode|encode, internal use
    private $outMode = 'raw'; // OUT_RAW|OUT_BIN  see setOutputBin()/setOutputRaw()
    private $inMode = 'raw'; // OUT_RAW|OUT_BIN, internal use
    private $bitData = '';
    public  $bitptr = 0;
    //---- decode
    public $ukRaw = ''; // input RAW data
    public $ukNorm = array(); // Normalized RAW data
    public $storeNorm = array();
    public $ukExtra = 0;
    public $ukptr = 0;
    public $ukdata = '';
    public $uk2data = '';
    public $dataDecoded = array();
    public $bitDecoded = '';
    public $decodePtr = 0;
    public $errPosition = -1;
    public $deltaTime = 0;
    public $prtTime = 0;
    //---- normalization
    private $doRAWnormalization = true; // decodeeRaw uses arrayNormalize(). 
    public $minM = 0; // the minimum  Mark in usec, set by irp_bitSpec constructor
    public $minS = 0; // the minimum Space in usec, set by irp_bitSpec constructor
    private $delta = 0;
    private $deltaM = 0;
    private $deltaS = 0;
    private $avgM = 0;
    private $avgS = 0;
    private $normmsg = ''; // error message by arrayNormalize()
    var $environ = array(); //---- var store 
    /*
     * Constructor, requires a valid IRP (string) or NULL
     */
    function __construct($newIRP = NULL)
      {
        if ($newIRP == NULL)
          {
            $this->isNull = true;
            $newIRP       = '{38k,800,lsb}<1,-1|1,-3>(0:1,1:1,D:8,F:8,30m)+'; // dummy, fake
          }
        else
            $this->isNull = false;
        $tmp = $this->IRP = str_replace(' ', '', $newIRP); // no spaces
        $tmp = $this->IRP = str_replace("\t", '', $tmp); // no tabs
        $tmp = $this->IRP = str_replace("\n", '', $tmp); // no 
        $tmp = $this->IRP = str_replace("\r", '', $tmp); // no 
        // GeneralSpec
        //		echo 'irp = ',$tmp.'<br>';
        $e   = 0;
        if ($k = irp_getRMatchBrace($tmp, '{', 0, '}'))
          {
            $this->setGeneralSpec(substr($tmp, 1, $k - 1));
            $e = ++$k;
          } //$k = irp_getRMatchBrace($tmp, '{', 0, '}')
        $tmp = trim(substr($tmp, $e));
        // BitSpec 
        $e   = 0;
        if ($k = irp_getRMatchBrace($tmp, '<', 0, '>'))
          {
            $this->baseBSpec = new irp_bitSpec($this, null, substr($tmp, 1, $k - 1));
            $e               = ++$k;
          } //$k = irp_getRMatchBrace($tmp, '<', 0, '>')
        //last = IRStream + [Definitions]
        $last   = trim(substr($tmp, $e));
        //  echo 'last = '.$last.'<br>';
        // Definitions
        $pieces = split('[{}]', $last); // here explode don't works (?)
        if (isset($pieces[1]))
            $this->setDefinitions(trim($pieces[1]));
        // IRstream
        // correction of a common error: <variations> out the IRstrem.
        // this is not correct, but common:
        //    {36.0k,268,msb}<-1,1|1,-1>[T=1][T=0](7,-6,3,D:4,1:1,T:1,1:2,0:8,F:8,15:4,C:4,-79m)+
        // this is correct:
        //    {36.0k,268,msb}<-1,1|1,-1>([T=1][T=0],7,-6,3,D:4,1:1,T:1,1:2,0:8,F:8,15:4,C:4,-79m)+
        if (($pieces[0][0]) == '[')
          {
            $this->IRStream = str_replace('](', '],', '(' . trim($pieces[0]));
          } //($pieces[0][0]) == '['
        else
            $this->IRStream = trim($pieces[0]);
        // Persistence           
        if ($this->doDataPermanence)
          {
            $oldData = $this->restoreData();
            $this->setValues($oldData, 'raw');
          }
        if ($this->baseBSpec == NULL)
            echo "*** ERROR: code can't parse the IRP[" . $this->IRP . "]. Verify.\n\r<br>";
      }
    //   
    function __destruct()
      {
        if ($this->doDataPermanence)
          {
            $this->saveData();
          }
        else
            @unlink(dirname(__FILE__) . self::DATAFILE);
      }
    /*
     * ENCODE function: builds a RAW IR command using
     *  - value-set: an array like: '$data_DirectTV['D']= 7; $data_DirectTV['F']= 37;'
     *     or a string like Expressions: '{D=0x3A, F=37}'
     *     or a HEX string  like 'A25E'
     *  - rrepeat (1...n)  (uses dittos or Variation as defined in IRP)  
     *  output is BIT (only data) or RAW (microsec., complete) 
     */
    // common <device-parameters> in IRP
    // D = Device code
    // S = Subdevice code
    // F = Function code (otherwise called OBC or Original Button Code)
    // C = Checksum
    // T = Toggle (for repetitions)
    public function encodeRaw($values, $rrepeat)
      {
        // set values
        if ($this->isNull)
            return '*** ENCODE unavailable. Protocol NULL';
        $this->mode        = 'encode';
        $this->errPosition = -1;
        $this->inMode      = self::OUT_RAW;
        if (gettype($values) == 'string')
          {
            if (strpos($values, "=") === false)
              {
                $this->setValuesHEX($values);
              } //strpos($values, "=") === false
            else
                $this->setValues($values, 'raw');
          } //gettype($values) == 'string'
        if (gettype($values) == 'array')
          {
            foreach ($values as $key => $val)
                $this->environ[$key] = $val;
          } //gettype($values) == 'array'
        // processes IRstream 	
        $mainStream = new irp_bitStream($this, $this->baseBSpec, $this->IRStream, $rrepeat);
        return $mainStream->analyzeRaw();
      }
    /*
     * DECODE function: returns the found data.
     * Requires only received RAW data.
     * Returns a value-set as string (raw) or as bitstream (bin)
     */
    public function decodeRaw($dataRaw)
      {
        if ($this->isNull)
            return '*** unavailable. Protocol NULL';
        // initialize  
        $store = array();
        unset($this->ukNorm);
        $this->storeNorm   = NULL;
        $this->mode        = 'decode';
        $this->inMode      = self::OUT_RAW; // reset, required by Verify
        $this->ukptr       = 0;
        $this->dataDecoded = array();
        $this->bitDecoded  = '';
        $this->errPosition = -1;
        $this->decodePtr   = 0;
        // set unknown raw     
        $err               = 0;
        $this->deltaTime   = 999999;
        $this->ukRaw       = trim($dataRaw);
        $errmsg            = '';
        if ($this->ukRaw[0] == '{')
          {
            // format RAW-1, RAW-2
            $parts  = explode('}', $this->ukRaw);
            $tmp    = explode(',', trim($parts[0]));
            $factor = trim($tmp[1]);
            $count  = $tmp[2];
            $times  = explode(self::CHAR_LIST, trim($parts[1])); // compressed RAW mode
            if (count($times) != $count)
              {
                $this->errPosition = 1;
                return "*** ERROR: found " . count($times) . ' RAW data, required ' . $count . '<br>';
              }
            for ($i = 0; $i < count($times); $i++)
              {
                if (trim($times[$i]) === '')
                    $err++;
                while ((($i + 1) < count($times)) && ($times[$i] * $times[$i + 1] > 0))
                  {
                    $times[$i + 1] += $times[$i]; // merges sequential mark/spaces (RAW-2 -> RAW-1 -> RAW-0)
                    $i++;
                  }
                $small = round($times[$i] * $factor);
                if ($small == 0)
                  {
                    $this->errPosition = $i;
                    //          echo "error 3 <br>";                
                    $errmsg = '*** ERROR: bad RAW value:[' . $times[$i] . '] @';
                    $err++;
                  }
                if ($err > 0)
                    $errmsg .= self::CHAR_LIST . $times[$i];
                if ((abs($small) > 1) && (abs($small) < $this->deltaTime))
                    $this->deltaTime = abs($small);
                $this->ukNorm[] = $small;
              } //$times as $t
          }
        else
          {
            // format RAW, RAW-0		
            $times = explode(self::CHAR_LIST, $dataRaw); // plain RAW mode
            for ($i = 0; $i < count($times); $i++)
              {
                if (trim($times[$i]) === '')
                    $err++;
                while ((($i + 1) < count($times)) && ($times[$i] * $times[$i + 1] > 0))
                  {
                    $times[$i + 1] += $times[$i]; // merges sequential mark/spaces (RAW -> RAW-0)
                    $i++;
                  }
                $small = round($times[$i]);
                if ($small == 0) // empty/0 not allowed
                  {
                    //           echo "error 4 <br>";                   
                    $this->errPosition = $i;
                    $errmsg            = '*** ERROR: bad RAW value:[' . $times[$i] . '] @';
                    $err++;
                  }
                if ($err > 0)
                    $errmsg .= self::CHAR_LIST . $times[$i];
                if ((abs($small) > 1) && (abs($small) < $this->deltaTime))
                    $this->deltaTime = abs($small);
                $this->ukNorm[] = $small;
              } //$times as $t
          }
        if ($err > 0)
            return ($errmsg . ' >>  Check data <br>');
        // normalization
        if ($this->doRAWnormalization)
            $this->storeNorm = $this->ukNorm = $this->arrayNormalize($this->ukNorm);
        // processes IRstream 
        $store         = $this->environ; // saves environ
        $this->prtTime = 0;
        $this->environ = array(); // void
        // used as base for time error in decoding, must exist > 1
        // if exist, better $this->tBase  
        if ($this->tBase > 1)
            $this->deltaTime = $this->tBase;
        $mainStream = new irp_bitStream($this, $this->baseBSpec, $this->IRStream, 1);
        $mainStream->analyzeRaw();
        $this->environ = $store; // restore environ
        if ($this->errPosition >= 0)
          {
            return ('*** ERROR: decoder fails near RAW[' . strval((int) $this->errPosition + 1) . ']');
          }
 //  echo ' DataDECODED before: <pre>';  print_r ($this->dataDecoded); echo '</pre>';
  		  
        // update S from S:4:4  
        foreach ($this->dataDecoded as $key => $value)
          {
            if (!ctype_alnum($key))
              {
                $pars = explode(':', $key);
  //  print_r($pars); echo ' from '.$key.'<br>';
                if (count($pars) == 3)
                  {
                    $val = 0;
                    switch ($pars[0][0])
                    {
                        case '~':
                            $pars[0] = substr($pars[0], 1);
                            $val     = ~$value & $this->BMASK[$pars[1]];
                            $val <<= $pars[2];
                            break;
                        case '-':
                            $pars[0] = substr($pars[0], 1);
                            $val     = -$value & $this->BMASK[$pars[1]];
                            $val <<= $pars[2];
                            break;
                        default:
                            if (ctype_alpha($pars[0]))
                              {
                                $val = $value & $this->BMASK[$pars[1]];
                                $val <<= $pars[2];
                              }
                            else
                                continue;
                    }
 //               echo ' pars[0] now '.$pars[0].'<br>';
                    if (isset($this->dataDecoded[$pars[0]]))
                        $this->dataDecoded[$pars[0]] |= $val;
                    else
                        $this->dataDecoded[$pars[0]] = $val;
                  }
                if (count($pars) == 2)
                  {			 
// errPosition		
				  if (ctype_digit($pars[0]))	{	
					   $val = $pars[0] & $this->BMASK[$pars[1]];
					   if ($val != $value) {
					     $errmsg = "*** ERROR: bad value [$key]=$value";
                         $this->errPosition = 0;
					      }					        
					   }
                  }
              }
          }
//      echo ' DataDECODED after: <pre>';  print_r ($this->dataDecoded); echo '</pre>';
        if ($this->isOutputRaw())
          {
            $tmp = '';
            foreach ($this->dataDecoded as $key => $value)
              {
                if (!(gettype($key) == 'integer' || ctype_digit($key))) // skips numeric constants
                    if (!isset($this->environ['_def_'][$key]) || !$this->isFunction($this->dataDecoded, $this->environ['_def_'][$key]))
                        if (!$this->isVariation($key) && ctype_alpha($key))
                            $tmp .= $key . '=' . $value . ',';
              } //$this->dataDecoded as $key => $value
            $tmp = '{' . rtrim($tmp, ',') . '}';
            return $tmp;
          } //$this->isOutputRaw()
        else
          {
            return $this->bitDecoded;
          }
      }
    /*
     * RAWprocess($raw, $rmode, [$frequence]) 
     * This utility processes a RAW|BIN stream (captured by HW sensor or from  encode/decode).
     * 1) RAW: Unifies double marks or double spaces
     *    BIN: Add spaces every 8 bit, right padded with zeros.
     *    If rmode IRP_RAW_WIDE (0) returns
     * 2) RAW: Finds a BASEtime giving a time error less than (deltaTime/TIME_FACTOR_ERR), and adds some infos 
     *    required to send the IR: {freq,BASEtime,data-num} To receive this format is required an integer array.
     *    BIN: Transforms in HEXSTRING (can be used with encodeRaw()) right padded with zeros.
     *    If rmode IRP_RAW_PACK (1) returns 
     * 3) RAW: Any data value>127 (or  <-127) is split in many fields, so all fields are BYTES. Adds {freq,BASEtime,data-num}
     *    This format will reduce the RAM used because to receive this format is required an byte array.
     *    BIN: Transforms in HEXBYTE, if required right padded with zeros.
     *    If rmode IRP_RAW_BYTE (2) returns 
     *  Note: if the parameter 'frequence' is present, this don't uses IRP: this function can be uses with any RAW stream.
     */
    public function RAWprocess($raw, $rmode, $frequence = NULL)
      {
        if (!isset($raw[0]))
            return;
		if ($raw[0] == '*')
            return;
        if (strpos($raw, irp_protocol::CHAR_LIST) === false) // data in BIN mode
          {
            $new    = '';
            $copies = explode(' ', $raw); // repetitions
            foreach ($copies as $sended)
              {
                if ($sended == '')
                    continue;
                if ($rmode == IRP_RAW_WIDE)
                  {
                    for ($i = 0; $i < strlen($sended); $i += 8)
                      {
                        $part = substr($sended, $i, 8);
                        $part .= '00000000';
                        $part = substr($part, 0, 8);
                        $new .= $part . ' ';
                      } //$i = 0; $i < strlen($sended); $i += 8
                    $new .= ' ';
                  } //$rmode == IRP_RAW_WIDE
                if ($rmode == IRP_RAW_PACK)
                  {
                    for ($i = 0; $i < strlen($sended); $i += 16)
                      {
                        $part = substr($sended, $i, 16);
                        $part .= '0000000000000000';
                        $part = substr($part, 0, 16);
                        $new .= sprintf('%04X', bindec($part));
                      } //$i = 0; $i < strlen($sended); $i += 16
                    $new .= ' ';
                  } //$rmode == IRP_RAW_PACK
                if ($rmode == IRP_RAW_BYTE)
                  {
                    for ($i = 0; $i < strlen($sended); $i += 8)
                      {
                        $part = substr($sended, $i, 8);
                        $part .= '00000000';
                        $part = substr($part, 0, 8);
                        $new .= sprintf('%02X', bindec($part)) . ' ';
                      } //$i = 0; $i < strlen($sended); $i += 8
                    $new .= ' ';
                  } //$rmode == IRP_RAW_BYTE
              } //$copies as $sended
            return trim($new);
          } //!(strpos($raw, irp_protocol::CHAR_LIST))
        //  RAW rmode -----------------  
        $useF = $frequence;
        if ($frequence == NULL)
            $useF = round($this->frequence); // uses frequence from IRP
        $times = explode(irp_protocol::CHAR_LIST, $raw);
        // merge and clean-up data
        $min   = abs($times[2]);
        $imax  = count($times) - 2; // because the unset(), count($times) will change, $imax not 
        for ($i = 0; $i <= $imax; $i++)
          {
            if (($times[$i] * $times[$i + 1]) > 0) //add if same sign
              {
                $times[$i + 1] += $times[$i];
                unset($times[$i]);
              } //(($times[$i] * $times[$i + 1]) > 0)
            if (($min > abs($times[$i + 1])) and ($times[$i + 1] != 0))
              {
                $min = abs($times[$i + 1]); // set $min
              } //($min > abs($times[$i + 1])) and ($times[$i + 1] != 0)
            if (isset($times[$i]) && ($times[$i] == 0))
                unset($times[$i]); // eliminates zeros
          } //$i = 0; $i < $imax; $i++
	// echo "found min = $min <br>";
        // restore index	
        $times = array_values($times);
        //  RAW processing 0 ------------  
        if ($rmode == IRP_RAW_WIDE)
            return implode(irp_protocol::CHAR_LIST, $times);
        // try to found a good deltaTime (also if  $this->tBase == 1)
        // get average of shorts values
        $min       = round($min * 1.33); // tolerance +/- 1/3
        $ssum      = 0;
        $n         = 0;
        $totTime   = 0;
        $last      = 0;
        //  excludes first and last times from totTime.
		$tot = count($times);
        foreach ($times as $key => $value)
	      if (irp_isInRange($key, $tot)){
            $x = abs($value);
            $totTime += $x;
            if (abs($x) <= $min )
              {
                $ssum += abs($x);
                $n++;
              }
          }
        //  uses  $this->tBase or average ( case not IRP)
		$this->deltaTime = $min;
        if (($frequence !== NULL) || ($this->tBase == 1)) // alone
          {
		  if ($n >0)
            $this->deltaTime = round($ssum / $n);
          }
        else
            $this->deltaTime = $this->tBase; // uses IRP data
        //  round errors reduction
        $factor = array(
            1.0,
            2.0,
            3.0,
            4.0,
            5.0,
            6.0,
            8.0,
            10.0,
            12.0,
            15.0,
            20.0,
            25.0,
            50.0,
            100.0
        );
        //
        $delta  = $this->deltaTime / irp_protocol::TIME_FACTOR_ERR; // required precision in compress process
        //  trims  $this->deltaTime on (max error > $delta) 
        $ntest  = 0;
        $errpos = 0;
        while (true)
          {
            $step = round($this->deltaTime / $factor[$ntest]); // 
            $err  = 0.0;
 //     echo 'deltaTime ='.$this->deltaTime.' step = '.$step.'<br>';      
            if ($step == 0)
                return;
			foreach ($times as $key => $t)
	            if (irp_isInRange($key, $tot)){	
                if ($t != 0)
                  {
                    $v = round($t / $step);
                    $e = ($t - $v * $step); //  absolute
                    if (abs($e) > $err)
                      {
                        $err    = abs($e);
                        $errpos = $i;
                      }
                  } //$t != 0
              } //$times as $t
            //  echo 'step = '.$step.' MaxError = '.$err.' @pos = '.$errpos.'<br>';      
            if ($err > $delta) // no good
              {
                $ntest++; // loops using a big factor[]
                continue;
              } //$err > $delta 
            if ($step < 1)
                $step = 1; // just in case
            // now trims step on total time    
            while (true)
              {
                $newTot = 1;
                $b      = array_values($times);
                $c      = array();
                for ($i = 0; $i < count($b); $i++)
                  {
                    // normalize last space	to 10	(last  value can change)
                    if ($i == (count($b) - 1))
                      {
                        $raw = -10 * ceil(abs($b[$i]) / ($step * 10));
                      }
                    else
                      {
                        $raw = round($b[$i] / $step);
                      }
               	    if (irp_isInRange($i, $tot))	
                         $newTot += abs($raw * $step);
                    $b[$i] = $raw;
                    while ($raw > 127)
                      {
                        $c[] = 127;
                        $raw -= 127;
                      } //$raw > 127
                    while ($raw < -127)
                      {
                        $c[] = -127;
                        $raw += 127;
                      } //$raw < -127
                    $c[] = $raw;
                  } //$i = 0; $i < count($b); $i++
                // echo "Old tot = $totTime   newTot = $newTot  <br>";	
                if (($frequence != NULL) || ($this->tBase == 1)) // case alone
                  {
                    $step2 = round($step * $totTime / $newTot); // loops if newTot <<>> totTime
                    // echo "Old step = $step   new step = $step2  <br>";	
                    if ($step2 == $step)
                        break;
                    $step = $step2;
                  }
                else
                    break; // with IRP don't trim: ok, so forces 'exact' timing 
              }
            //	now $b[], $c[], and $step are ok
            // normalize last space
            //  RAW processing 1 ------------  
            if ($rmode == IRP_RAW_PACK)
                return '{' . $useF . ',' . $step . ',' . count($b) . '}' . implode(irp_protocol::CHAR_LIST, $b);
            //  RAW processing 2 ------------  
            if ($rmode == IRP_RAW_BYTE)
                return '{' . $useF . ',' . $step . ',' . count($c) . '}' . implode(irp_protocol::CHAR_LIST, $c);
            echo 'ERROR: bad rmode value, only 0,1,2 ! <br>';
            return;
          } //true
      }
    /* 
     *  In function of IR signal straight, the integrator/smith trigger, in the IR detector, 
     *  can under-estimate mark times (and over-estimate spaces). 
     *  This try normalization of RAW data without use the IRP.
     */
    public function RAWnormalize($RAWdata)
      {
        $array1 = explode(irp_protocol::CHAR_LIST, $RAWdata); // plain RAW mode, RAW-0 or RAW-1
        $array2 = $this->arrayNormalize($array1, false);
        return implode(irp_protocol::CHAR_LIST, $array2);
      }
    //  This function try a dynamic normalization of the raw signal (as array).
    //  Uses different algorithms if useIRP = true|false
    private function arrayNormalize($dataArray, $useIRP = true)
      {
//	  echo "<pre>";print_r($dataArray);echo "</pre><br>";
        $this->normmsg = '';
        // echo 'expected   -  mark: '.$this->minM.' space: '.$this->minS.'<br>';
        // test condition, usually ok after __constructor, ever ok after first decode
        if ($useIRP && (!(($this->minM > 0) && ($this->minS < 0))))
          {
            $this->normmsg = 'NO RAW NORMALIZATION because min/max code not set';
            return $dataArray; // can't normalize
          }
		$tot = count($dataArray);
        $rawminM = 99999999;
        $rawminS = -99999999;
        // find min 
      foreach ($dataArray as $key => $time1)
	      if (irp_isInRange($key, $tot)){
            if ($time1 > 0 && ($time1 < (int) $rawminM))
                $rawminM = $time1;
            if ($time1 < 0 && ($time1 > (int) $rawminS))
                $rawminS = $time1;
		    }
        //  echo 'minimun    -  mark: '.$rawminM.' space: '.$rawminS.'<br>';
        // limit min values to do averages: +/- 30%, limit +/- 90u 
        $rawminM1 = round($rawminM +90);
		$rawminM2 = round($rawminM * 1.30);
	    $rawminM = ($rawminM2 < $rawminM1 )? $rawminM2:$rawminM1;
	    $rawminS1 = round($rawminS -90);
        $rawminS2 = round($rawminS * 1.30);
 	    $rawminS =($rawminS2 > $rawminS )? $rawminS2:$rawminS1;
        //  echo ' test-min  -  mark: '.$rawminM.' space: '.$rawminS.'<br>';
        // average of low times
        $sumM    = 0;
        $countM  = 0;
        $sumS    = 0;
        $countS  = 0;
        foreach ($dataArray as $key => $time1)
 	      if (irp_isInRange($key, $tot)){
            if ($time1 > 0 && $time1 < $rawminM)
              {
                $sumM += $time1;
                $countM++;
              }
            if ($time1 < 0 && $time1 > $rawminS)
              {
                $sumS += $time1;
                $countS++;
              }
		   }
          
        if (($countM == 0) || ($countS == 0))
          {
            $this->normmsg = 'NO RAW NORMALIZATION, less than '.(SKIPFIRST + SKIPLAST).' values.';
            return $dataArray; // don't normalize
          }
        $this->avgM = round($sumM / $countM);
        $this->avgS = round($sumS / $countS);
        // correction average vs expected
        if ($useIRP == true)
          {
            $this->deltaM = $this->minM - $this->avgM;
            $this->deltaS = $this->minS - $this->avgS;
            // using same value for M and S, preserves total time   
            $this->delta  = round(($this->deltaM + $this->deltaS) / 2);
            //  echo ' Marks  - required:  '.$this->minM.'  average:  '.$this->avgM.'  delta: '.$this->deltaM.'<br>';
            //  echo ' Spaces - required: '.$this->minS. '  average: '.$this->avgS. '  delta: '.$this->deltaS.'  adjust: '. $this->delta.'<br>';
          }
        else
          {
            $factor      = round(-$this->avgS / $this->avgM);
            $this->delta = round((-$factor * $this->avgM - $this->avgS) / ($factor + 1));
            //  echo ' Marks  - average:  '.$this->avgM.'  becomes:  '.($this->avgM+$this->delta).'<br>';
            //  echo ' Spaces - average: '.$this->avgS. '  becomes: '.($this->avgS+$this->delta).'  adjust: '. $this->delta.'<br>';
          }
        // more safety check
        if ($useIRP)
           if ((abs($this->deltaM) > abs($this->minM) / 4) || (abs($this->deltaS) > abs($this->minS) / 4))
              {
                // some gone bad, no normalization
                $this->normmsg = 'NO RAW NORMALIZATION because security check';
                return $dataArray;
              }
        if (!$useIRP)
            if ((abs($this->deltaM) > abs($this->avgM) / 4) || (abs($this->deltaS) > abs($this->avgS) / 4))
              {
                // some gone bad, no normalization
                $this->normmsg = 'NO RAW NORMALIZATION because security check';
                return $dataArray;
              }
        $norm = array();
        foreach ($dataArray as $time1)
          {
            $norm[] = $time1 + $this->delta;
          }
        return $norm;
      }
    /*
     *  Utility to test data from decode (if possible)
     *  OUTPUT: 
     *       if $verbose = false
     *          a string: <protocol-parameters>|<device-parameters>|<verify-result> like:  {D=10,F=37}|{}|true
     *       if $verbose = true
     *          a string with max 4 sections: 
     *          RAW NORMALIZED, DECODED VARIABLES, CALCULATED VARIABLES, VERIFIED VARIABLES (see full-test.php)
     */
    //  notes on IRP notation
    //
    //  1)  To verify the decoded value against an Expressions, verify that the value used in the IRstream and the value given
    //      by the expression have same number of bit. If not, add some like ':4' or '&15' (limits to 4 bit) to Expression.
    //  Example: see decode-test.php: 
    //     $XMP='{38k,136,msb}<210u,-760u>(<0:1|0:1,-1|0:1,-2|0:1,-3|0:1,-4|0:1,-5|0:1,-6|0:1,-7|0:1,-8|0:1,-9|0:1,-10|0:1,
    //             -11|0:1, -12|0:1,-13|0:1,-14|0:1,-15>(T=0,(S:4:4,C1:4,S:4,15:4,OEM:8,D:8,210u,-13.8m,S:4:4,C2:4,T:4,S:4,
    //             F:16,210u,-80.4m,T=8)+)){C1=-(15+S+S::4+15+OEM+OEM::4+D+D::4),C2=-(15+S+S:4+T+F+F::4+F::8+F::12)}'; 
    //        modify the IRP so: ....{C1=-(15+S+S::4+15+OEM+OEM::4+D+D::4):4,C2=-(15+S+S:4+T+F+F::4+F::8+F::12)&15}, 
    //        because in IRstrem we found 'C1:4' and 'C2:4'
    //
    //  2)  Sometime we have 2 sets of values: the first set are <device-parameters'> the second set are <protocol-parameters>
    //       and the Expressions in IRP allows to calculate the second set from the first. 
    //       It is possible and useful to add at IRP also the inverse Expressions.
    //  Example: see full-test.php 
    //     $Fujitsu_Aircon='{38.4k,413}<1,-1|1,-3>(8,-4,20:8,99:8,0:8,16:8,16:8,254:8,9:8,48:8,H:8,J:8,K:8, L:8, M:8,N:8,
    //              32:8,Z:8,1,-104.3m)+ {H=16*A + wOn, J=16*C + B, K=16*E:4 + D:4, L=tOff:8, M=tOff:3:8+fOff*8+16*tOn:4,
    //              N=tOn:7:8+128*fOn,Z=256-(H+J+K+L+M+N+80)%256}';  
    //      with [A:0..15,wOn:0..1,B:0..15, C:0..15,D:0..15,E:0..15,tOff:0..1024,tOn:0..1024,fOff:0..1,fOn:0..1]
    //      The Expressions in IRP allows to calculate H,J,K,L,M,N and Z (protocol-parameters) from A,wOn,B,C,D,E,tOff,tOn,fOff,
    //      fOn (device-parameters) and they are used in ENCODING phase.
    //      On decoding, we need inverse expressions, calculating  A,wOn,B,C,D,E,tOff,tOn,fOff,fOn from H,J,K,L,M,N,Z.
    //      We can add these Expressions to IRP, and that will not influence the ENCODE phase (values have precedence on
    //      expressions) but in DECODE phase we can get from RAW not only H,J,K,L,M,N,Z but also the device parameters 
    //      A,wOn,B,C,D,E,tOff,tOn,fOff,fOn. 
    //      See $Fujitsu_Aircon_modified. 
    //
    // 3)   Typing mistakes are not rare in public IRP. If ENCODE/DECODE/VERIFY crashes look with care to IRP.
    //
    public function dataVerify($verbose)
      {
        if ($this->isNull)
            return '*** unavailable. Protocol NULL';
        if (strlen($this->bitDecoded) == 0)
          {
            echo '+++ WARNING: dataVerify() must be called only after decodeRaw() <br>';
            return;
          } //strlen($this->bitDecoded) == 0
       if ($this->errPosition > 0) // in case of error
          {
            if (!$verbose)
                return '{}|{}|false';
            $out1 = '';
            $out2 = '';
            $msg  = '<br>------------- ERROR @item[' . ($this->errPosition + 1) . '] / ' . count($this->ukNorm) . ' <br>';
            $copies = explode(' ', $this->bitDecoded); // repetitions
            foreach ($copies as $sended)
              {
                for ($i = 0; $i < strlen($sended); $i += 8)
                  {
                    $part = substr($sended, $i, 8);
                    $part .= '00000000';
                    $part = substr($part, 0, 8);
                    $out1 .= $part . ' ';
                  } //$i = 0; $i < strlen($sended); $i += 8
                for ($i = 0; $i < strlen($sended); $i += 16)
                  {
                    $part = substr($sended, $i, 16);
                    $part .= '0000000000000000';
                    $part = substr($part, 0, 16);
                    $out2 .= sprintf('%04X', bindec($part));
                  } //$i = 0; $i < strlen($sended); $i += 16
                $out1 .= '- ';
                $out2 .= ' - ';
              } //$copies as $sended
            $msg .= 'BIN-0 = ' . rtrim($out1, ' -') . '<br>';
            $msg .= 'BIN-1 = ' . rtrim($out2, ' -') . '<br>';
            return trim($msg);
          }
 //     echo "<pre>"; print_r($this->dataDecoded); echo "</pre>";
        $store         = $this->environ;
        $this->environ = $this->dataDecoded;
        $mainStream    = new irp_bitStream($this, $this->baseBSpec, $this->IRStream, 1);
        // list of data_found
        $data_found    = array();
        $var_name      = array();
        foreach ($this->dataDecoded as $key => $value)
          {
            $tmp = explode(':', $key);
            if (!(gettype($tmp[0]) == 'integer' || ctype_digit($tmp[0])))
            //              if (ctype_alpha($key[0]) && ctype_alnum($key))  // only simple variables
                $data_found[] = $key;
            $var_name[$tmp[0]] = $key;
          }
        // list of calculated
        // list of verify
        $calc_expression   = array();
        $verify_expression = array();
        if (isset($store['_def_']))
            foreach ($store['_def_'] as $var => $expression)
              {
                //            if(! array_key_exists ($var,$this->dataDecoded ))
                if (!array_key_exists($var, $var_name))
                  {
                    $calc_expression[] = $var;
                  }
              }
        // list of verify2
        $verify_data = array();
        foreach ($this->dataDecoded as $key => $value)
          {
            $tmp = explode(':', $key);
            if (!(gettype($tmp[0]) == 'integer' || ctype_digit($tmp[0])))
                if (!ctype_alnum($tmp[0]))
                  {
                    $verify_data[] = $key;
                  }
          }
        //   echo ' LIST DataDECODED: <br>';
        //   print_r ($this->dataDecoded); echo '<br>';
        // building output 
        if ($verbose)
          {
            $result = 'Processed ' . count($this->ukNorm) . ' samples. <br>';
            $result .= 'Processed ' . strlen($this->bitDecoded) . ' bits. <br>';
            //            if ($this->doRAWnormalization){
            $result .= '<br>------------- RAW NORMALIZED <br>';
            $result .= ' Marks  - required:  ' . $this->minM . '  average:  ' . $this->avgM . '  delta: ' . $this->deltaM . '<br>';
            $result .= ' Spaces - required: ' . $this->minS . '  average: ' . $this->avgS . '  delta: ' . $this->deltaS . '  adjust: ' . $this->delta . '<br>';
            if ($this->getNormError() !== false)
                $result .= ' WARNING ' . $this->getNormError() . '<br>';
            //                 }
            $result .= '<br>------------- DECODED VARIABLES <br>';
          }
        else
            $result = '{';
        foreach ($data_found as $key)
          {
            if ($verbose)
                $result .= $key . ' = ' . $this->xd($this->environ[$key]) . '<br>';
            else if (!isset($store['_def_'][$key]) || !$this->isFunction($this->environ, $store['_def_'][$key]))
                if (!$this->isVariation($key) && ctype_alpha($key[0]) && ctype_alnum($key)) // only simple variables
                    $result .= $key . '=' . $this->environ[$key] . ',';
          }
        if ($verbose)
          {
            if (count($calc_expression) > 0)
                $result .= '<br>------------- CALCULATED VARIABLES <br>';
          }
        else
            $result = rtrim($result, ',') . '}|{';
        foreach ($calc_expression as $key)
          {
            $v                   = $mainStream->evalExp($store['_def_'][$key]);
            $this->environ[$key] = $v;
            if ($verbose)
                $result .= $key . ' = ' . $store['_def_'][$key] . ' = ' . $this->xd($v) . '<br>';
            else
                $result .= $key . '=' . $v . ',';
          }
        if (isset($store['_def_']))
            foreach ($store['_def_'] as $var => $expression)
              {
                //                if(array_key_exists ($var,$this->dataDecoded ))
                if (array_key_exists($var, $var_name))
                    if ($this->isFunction($this->environ, $expression))
                        $verify_expression[] = $var;
              }
        if ($verbose)
          {
            if ((count($verify_expression) + count($verify_data)) > 0)
                $result .= '<br>------------- VERIFIED VARIABLES <br>';
          }
        else
            $result = rtrim($result, ',') . '}|';
        $allok = true;
        foreach ($verify_expression as $key)
          {
            //  echo ' verify_expression  > ' .$key. ' EXpression: '.$store['_def_'][$key].'<br>';
            $v = $mainStream->evalExp($store['_def_'][$key]);
            $r = $this->xd($v);
            // transforms negative number in positive 8 bit             
            if ($v < 0)
              {
                $b  = decbin($v);
                $b  = '00000000' . substr($b, -8);
                $v2 = bindec($b);
                $r  = $this->xd($v) . ' => ' . $this->xd($v2);
                $v  = $v2;
              }
            if (!isset($this->environ[$key]))
                $this->environ[$key] = $v;
            $isok = ($v == $this->dataDecoded[$var_name[$key]]);
            $allok &= $isok;
            if ($verbose)
                $result .= $key . ' = ' . $this->xd($store['_def_'][$key]) . ' = ' . $r . ($isok ? '  OK' : ' ** BAD **') . '<br>';
          }
        foreach ($verify_data as $key)
          {
            //   echo ' verify_data > ' .$key.'<br>';
            $v = $mainStream->evalExp($key);
            //   echo ' result > ' .$v.'<br>';
            $r = $this->xd($v);
            // transforms negative number in positive 8 bit             
            if ($v < 0)
              {
                $b  = decbin($v);
                $b  = '00000000' . substr($b, -8);
                $v2 = bindec($b);
                $r  = $this->xd($v) . ' => ' . $this->xd($v2);
                $v  = $v2;
              }
            $allok &= ($v == $this->dataDecoded[$key]);
            //  echo ' verify_data > ' .$key.' => '.$v.' vs '.$this->dataDecoded[$key].'<br>';              
            if ($verbose)
                $result .= '' . $key . ' = ' . $r . (($v == $this->dataDecoded[$key]) ? '  OK' : ' ** BAD ( found ' . $this->dataDecoded[$key] . ') **') . '<br>';
          }
        if (!$verbose)
            $result .= ($allok && ($this->errPosition < 0)) ? 'true' : 'false';
        else 
		    if ($this->errPosition == 0)
    		    $result .= '*** ERROR in fixed values. <br>';
        $this->environ = $store;
        return $result;
      }
    /*
     * setter getter  functions
     */
    public function getFrequence()
      {
        return ($this->frequence);
      }
    public function setOutputBin()
      {
        $this->outMode = self::OUT_BIN;
      }
    public function setOutputRaw()
      {
        $this->outMode = self::OUT_RAW;
      }
    public function isError()
      {
        return ($this->errPosition >= 0);
      }
    public function setNormalize($norm)
      {
        $this->doRAWnormalization = ($norm == true);
      }
    public function getNormError()
      {
        if ($this->normmsg == '')
            return false;
        return $this->normmsg;
      }
    public function getNormRAW()
      {
        if ($this->storeNorm == NULL)
            return false;
        return implode(irp_protocol::CHAR_LIST, $this->storeNorm);
      }
    public function setDataPermanence()
      {
        $this->doDataPermanence = true;
      }
    public function resetData()
      {
        if (isset($this->environ['_def_']))
          {
            $tmp                    = $this->environ['_def_'];
            $this->environ          = array(); // clear all data, use it if Permanence on
            $this->environ['_def_'] = $tmp;
          }
        else
          {
            $this->environ = array();
          }
      }
    /*
     *  utility for test/debug: dumps internal data from irp_protocol
     */
    public function toString()
      {
        echo "IRP        = " . $this->IRP . '<br>';
        echo "Frequence  = " . $this->frequence . '<br>';
        echo "timeBase   = " . $this->tBase . '<br>';
        echo "Order      = " . $this->order . '<br>';
        if ($this->baseBSpec != null)
          {
            echo "Coded bits = " . $this->baseBSpec->bitCoded . '<br>';
            switch ($this->baseBSpec->encoding)
            {
                case 0:
                    echo "Encoding   = UNKNOWN" . '<br>';
                    break;
                case 1:
                    echo "Encoding   = VARIABLE SPACE" . '<br>';
                    break;
                case 2:
                    echo "Encoding   = VARIABLE MARK" . '<br>';
                    break;
                case 3:
                    echo "Encoding   = VARIABLE PHASE" . '<br>';
                    break;
                case 4:
                    echo "Encoding   = VARIABLE MARK SPACE" . '<br>';
                    break;
           } //$this->baseBSpec->encoding
            echo "Coding map =";
            print_r($this->baseBSpec->codeBit);
            echo '<br>MaxTime in code = ' . $this->baseBSpec->getMaxCodeTime() . 'u [' . round($this->baseBSpec->getMaxCodeTime() / $this->tBase) . '] <br>';
          } //$this->baseBSpec != null
        else
          {
            echo '** BitSpec not available ';
          }
        echo ' ---------------- <br>';
        echo "Runtime Exp = ";
        print_r($this->environ);
        echo '<br>';
        echo "IRStream    = " . $this->IRStream;
        echo '<br> ---------------- <br>';
      }
    /*
     * locals Access functions 
     */
    function isDecode()
      {
        return $this->mode == 'decode';
      }
    function isInputRaw()
      {
        return $this->inMode == self::OUT_RAW;
      }
    function isOutputRaw()
      {
        return $this->outMode == self::OUT_RAW;
      }
    function getOutMode()
      {
        return $this->outMode;
      }
    function getBits($count)
      {
        $s = $this->bitptr;
        $this->bitptr += $count;
        return substr($this->bitData, $s, $count);
      }
    // ------------------------------------------------------- private locals
    private function saveData()
      {
        $tmp = '{';
        foreach ($this->environ as $key => $value)
          {
            if ($key[0] != '_')
              {
                $tmp .= $key . '=' . $value . ',';
              }
          }
        $saveStr = rtrim($tmp, ',') . '}';
        //  echo 'save store = '.$saveStr.'<br>';
        file_put_contents(dirname(__FILE__) . self::DATAFILE, $saveStr);
      }
    private function restoreData()
      {
        $data = '';
        try
          {
            $data = @file_get_contents(dirname(__FILE__) . self::DATAFILE);
          }
        catch (Exception $e)
          {
            ;
          }
        if ($data === false)
          {
            //  echo 'read store = FALSE <br>';
            return '{}';
          }
        //  echo 'read store = '.$data.'<br>';
        return trim($data);
      }
    // Test equation vs var array: equation uses only vars in array?
    private function isFunction($vars, $exp)
      {
        $tmp = $exp;
        foreach ($vars as $key => $val)
          {
            $tmp = str_replace($key, '', $tmp);
          }
        $result = true;
        //   echo ' Equation: '.$exp.' => '.$tmp.'<br>';
        for ($i = 0; $i < strlen($tmp); $i++)
            $result &= !(ctype_alpha($tmp[$i]));
        return $result;
      }
    //  convert number (int) to HEX for print 
    private function xd($number)
      {
        if ((gettype($number) == 'string') && !ctype_digit($number))
            return $number;
        $tmp = '0000' . strtoupper(dechex($number));
		if (strlen($tmp)>8)
		   $tmp = substr($tmp, -8);
		 else
           $tmp = substr($tmp, -4);
        return '[0x' . $tmp . '] ' . $number;
      }
    // Test is var a variation?: i.e. exists in IRstream a item like var=X ?
    private function isVariation($var)
      {
        $result = (strpos($this->IRStream, $var . '=') !== false);
        return $result;
      }
    // adds Definitions, like {T=1,C=-(D + F:4 + F:4:4)} or T=1,C=-(D + F:4 + F:4:4)
    // uses a sub-array: environ['_def_']  to store Definitions
    private function setDefinitions($espressions)
      {
        $temp = irp_onion($espressions, '{', '}');
        $exps = explode(',', $temp);
        foreach ($exps as $aExp)
          {
            $parts                                   = explode('=', $aExp);
            $this->environ['_def_'][trim($parts[0])] = trim($parts[1]);
          } //$exps as $aExp
      }
    // processes Values string, like {T=1,C=-0x16} or T=1,C=-0x16
    // expression must be numbers (not expression with vars)
    private function setValues($espressions)
      {
        $temp = irp_onion($espressions, '{', '}');
        $exps = explode(',', $temp);
        foreach ($exps as $aExp)
          {
            if (strpos($aExp, '=') !== false)
              {
                $parts                          = explode('=', $aExp);
                // to accept ex and octal values uses eval().
                $this->environ[trim($parts[0])] = eval(' return ' . $parts[1] . ';');
                //   echo 'Value '.trim($parts[0]). ' = '.$this->environ[trim($parts[0])].'<br>';
              }
          } //$exps as $aExp
      }
    // processes Values HEX string, like '7D00F0F2'  or '7D 00 F0 F2'  
    private function setValuesHEX($hexString)
      {
        $this->inMode  = self::OUT_BIN;
        $this->bitData = '';
        $this->bitptr  = 0;
        for ($i = 0; $i < strlen($hexString); $i++)
          {
            if (ctype_xdigit($hexString[$i]))
              {
                $tmp = '0000' . decbin(hexdec($hexString[$i]));
                $this->bitData .= substr($tmp, -4);
              } //ctype_xdigit($hexString[$i])
          } //$i = 0; $i < strlen($hexString); $i++
      }
    // processes GeneralSpec, like {38k,600,msb} or 38k,600u ,msb
    // tBase, if exist, is converted to microseconds
    // else is set to 1
    private function setGeneralSpec($espression)
      {
        $temp            = irp_onion($espression, '{', '}');
        // defaults
        $this->frequence = 0;
        $this->order     = 'lsb';
        $this->tBase     = 1;
        // in any order  
        $values          = explode(',', $temp);
        foreach ($values as $tmp)
          {
            $tmp = strtolower(trim($tmp)); // just in case...
            if ($tmp[strlen($tmp) - 1] == 'k')
              {
                $this->frequence = rtrim($tmp, 'k');
                continue;
              } //$tmp[strlen($tmp) - 1] == 'k'
            if (($tmp == 'lsb') || ($tmp == 'msb'))
              {
                $this->order = $tmp;
                continue;
              } //($tmp == 'lsb') || ($tmp == 'msb')
            // else is tBase			
            $this->tBase = $tmp;
          } //$values as $tmp
        // adjust tBase to usec
        $strVal = $this->tBase;
        // only p, u in tBase		
        if (strlen($strVal) >= 2)
            switch ($strVal[strlen($strVal) - 1])
            {
                case 'p':
                    $this->tBase = round(rtrim($strVal, 'p') * 1000.0 / $this->frequence);
                case 'u':
                    $this->tBase = (int) (rtrim($strVal, 'u'));
            } //$strVal[strlen($strVal) - 1]
      }
  } // ends irp_protocol class
/*
 * ---------------- private internal class
 * stores informations about bitSpec (bit coding rules)
 * low level number/bitfield <=> raw methods
 * recursive
 */
class irp_bitSpec
  {
    // internal use
    const UNKNOWN = 0; // code style const
    const VARIABLE_SPACE = 1;
    const VARIABLE_MARK = 2;
    const VARIABLE_MARK_SPACE = 4;
    const PHASE_SHIFT = 3;
    //    
    private $myProtocol = null; // the Protocol global object  
    private $myParent = null; // the parent irp_bitSpec | null (in case of recursive use)
    // bit coding
    public $bitCoded = 0; // number of bit coded
    public $encoding = 0; // encoding style: one of UNKNOWN...PHASE_SHIFT
    public $codeBit = array(); // the resulting code
    public $normCode = array(); // the normalized code (i.e. with mark (spaces) consecutive added) in microsec.
    private $maxCode = 0; // the max value in code, after normalization
    // data management
    private $bitbuffer = ''; // FIFO for BIT before codification
    // constructor:  the irp_protocol, a parent irp_bitSpec or null, the bitspec string (<...>)
    function __construct($protocol, $aparent, $bitspec)
      {
        $this->myProtocol = $protocol;
        $this->myParent   = $aparent;
        $this->bitbuffer  = '';
        $ncode            = 0;
        $this->maxCode    = 0;
        $n                = 0;
        //
        $tmp              = irp_onion($bitspec, '<', '>'); // cleanup
        $values           = split('[|]', $tmp);
        // echo '<br> bitSpec:'; print_r($values); echo '<br>';
        // bits coding rules   
        // like <1,-1>, <8:4|4:4|2:4|1:4>, <1,-1|1,-2|2,-1|2,-2>, <-4,2|-3,1,-1,1|-2,1,-2,1|-1,1,-3,1>
        foreach ($values as $acode)
          {
            $bits = split(',', $acode);
            $i    = 0;
            foreach ($bits as $abit)
              {
                if (strpos($abit, ':'))
                  {
                    $parts = split('[:]', $abit . ':0');
                    $tmp   = $this->myParent->bitfield2int($abit, false);
                    $code  = $this->myParent->toStream($tmp, $parts[1], 'code');
                    $codes = explode(irp_protocol::CHAR_LIST, $code);
                    foreach ($codes as $bit)
                      {
                        $this->codeBit[$ncode][$i++] = $bit;
                      } //$codes as $bit
                  } //strpos($abit, ':')
                else
                  {
                    $this->codeBit[$ncode][$i++] = $abit;
                  }
              } //$bits as $abit
            $ncode++;
          } //$values as $acode
        // here code  normalization     
        $ncode = 0;
        foreach ($this->codeBit as $testc) // for all bits combinations (2,4, 8, 16)
          {
            // normalizes code values:  1, -2, -5 => 1,-7 
            $this->normCode[$ncode][0] = $this->toRaw($testc[0]);
            $p                         = 0;
            for ($k = 1; $k < count($testc); $k++)
              {
                $c = $this->toRaw($testc[$k]);
                if ($this->normCode[$ncode][$p] * $c > 0) // same sign
                  {
                    $this->normCode[$ncode][$p] += $c;
                  } //$norm[$p] * $c > 0
                else
                  {
                    $this->updateminMax($this->normCode[$ncode][$p]);
                    $p++;
                    $this->normCode[$ncode][$p] = $c;
                  }
              } //$k = 1; $k < count($testc); $k++
            $this->updateminMax($this->normCode[$ncode][$p]);
            $ncode++;
          }
        // test coding
        $mark  = true;
        $space = true;
        $phase = true;
        $sign  = true;
        if (($ncode > 1) && (gettype($this->normCode[0]) == 'array') && isset($this->normCode[0][1]))
          {
            for ($i = 1; $i < $ncode; $i++)
              {
                $mark  &= ($this->normCode[$i - 1][0] == $this->normCode[$i][0]);  // mark constant
                $space &= ($this->normCode[$i - 1][1] == $this->normCode[$i][1]);  // space constant
                $phase &= ($this->normCode[$i - 1][0] == $this->normCode[$i][1]);  // inverted
                $phase &= ($this->normCode[$i - 1][1] == $this->normCode[$i][0]);  // inverted
                $sign  &= (($this->normCode[$i - 1][0] * $this->normCode[$i][0]) > 0); // first code constant sign
              } //$i = 1; $i < $ncode; $i++
          } //($ncode > 1) && (gettype($this->codeBit[0]) == 'array') && isset($this->codeBit[0][1])
        $this->encoding = self::UNKNOWN;
        if (!($mark and $space and $phase and $sign))
          {
            if ($mark)
                $this->encoding = self::VARIABLE_SPACE;
            if ($space)
                $this->encoding = self::VARIABLE_MARK;
           if (!$space && !$mark && $sign)
                $this->encoding = self::VARIABLE_MARK_SPACE;				
            if ($phase || !$sign) // PHASE_SHIFT extended to all codes with first sign not constant
                
            // useful in decode phase
                $this->encoding = self::PHASE_SHIFT;
          } //!($mark and $space and $phase and $sign)
        //         $this->encoding = self::PHASE_SHIFT;  
        // bitCoded from ncode
        switch ($ncode)
        {
            case 1:
                $this->bitCoded = 1;
                break;
            case 2:
                $this->bitCoded = 1;
                break;
            case 4:
                $this->bitCoded = 2;
                break;
            case 8:
                $this->bitCoded = 3;
                break;
            case 16:
                $this->bitCoded = 4;
                break;
            default:
                echo "+++ WARNING: bitSpec <$bitspec> defines only $ncode codes <br>";
        } //$ncode
        //  echo '<br>MaxTime in code = '.$this->getMaxCodeTime().'u ['.round($this->getMaxCodeTime()/$this->myProtocol->tBase).'] <br>';
      }
    // used by __construct, sets myProtocol->minM, myProtocol->minS, $this->maxCode
    // statistics from code used in normalize 
    private function updateminMax($duration)
      {
        if ($duration > 0)
          {
            if ($this->myProtocol->minM == 0)
              {
                $this->myProtocol->minM = $duration;
              }
            else if ($this->myProtocol->minM > $duration)
                $this->myProtocol->minM = $duration;
          }
        if ($duration < 0)
          {
            if ($this->myProtocol->minS == 0)
              {
                $this->myProtocol->minS = $duration;
              }
            else if ($this->myProtocol->minS < $duration)
                $this->myProtocol->minS = $duration;
          }
        // set   $this->maxCode, used to  find durations in decode                          
        if (abs($duration) > $this->maxCode)
            $this->maxCode = abs($duration);
      }
    // get max number in code, for skip header, etc...
    public function getMaxCodeTime()
      {
        return $this->maxCode;
      }
    // to RAW: returns an integer, microsecond, processes only numeric
    // don't processes variables  like Au
    public function toRaw($strVal)
      {
        switch ($strVal[strlen($strVal) - 1])
        {
            case 'p':
                return round(rtrim($strVal, 'p') * 1000.0 / $this->myProtocol->frequence);
            case 'u':
                return (int) (rtrim($strVal, 'u'));
            case 'm':
                return (int) (rtrim($strVal, 'm') * 1000);
        } //$strVal[strlen($strVal) - 1]
        return round($strVal * $this->myProtocol->tBase);
      }
    /*
     * Returns all bit in $buffer as RAW stream or BIT stream
     */
    public function flushBitBuffer()
      {
        if (strlen($this->bitbuffer) == 0)
            return '';
        if ($this->myProtocol->isOutputRaw())
          {
            // raw mode   
            $result = '';
            for ($i = strlen($this->bitbuffer) - $this->bitCoded; $i >= 0; $i -= $this->bitCoded)
              {
                $tcoded = '';
                $y      = bindec(substr($this->bitbuffer, $i, $this->bitCoded));
                foreach ($this->normCode[$y] as $bit)
                    $tcoded .= irp_protocol::CHAR_LIST . $bit;
                $result = $tcoded . $result;
              } //$i = strlen($buffer) - $this->bitCoded; $i >= 0; $i -= $this->bitCoded
          } //$this->myProtocol->isOutputRaw()
        else
          {
            // bin mode
            $result = $this->bitbuffer;
          }
        // echo ' buffer ='.$buffer.' encoded: '.$result.'<br>'; 
        $this->bitbuffer = '';
        return $result;
      }
    /* 
     * encode: get duration (fixed value)
     * decode: consume duration from RAW
     */
    public function duration2raw($exp)
      {
        // before flush BitBuffer to result
        $result = $this->flushBitBuffer();
        if ($this->myProtocol->isDecode())
          {
            $test = $this->toRaw($exp);
 //         echo ' duration2raw start: duration ='.$exp.' ==>  ' . $test . ' <br>';
            $p    = $this->myProtocol->ukptr;
            if ($p >= count($this->myProtocol->ukNorm))
                return $result;
            if ($test * $this->myProtocol->ukNorm[$p] < 0)
              {
  //              echo ' duration2raw BAD duration @'.$p.' required = ' . $test . ' Found = ' . $this->myProtocol->ukNorm[$p] . ' <br>';
                if (!$this->myProtocol->isOutputRaw())
                    return $result;
                // adds to result this duration     
                return (ltrim($result . irp_protocol::CHAR_LIST . $this->toRaw($exp), irp_protocol::CHAR_LIST));
              }
            if (($this->testTime($test, $this->myProtocol->ukNorm[$p])))
              {
  //             echo ' duration2raw FOUND duration @'.$p.' required = ' . $test . ' Found = ' . $this->myProtocol->ukNorm[$p] . ' <br>';
                $this->myProtocol->ukptr++;
              } //$test == $this->myProtocol->ukNorm[$p]
            else
              {
                //               if (($p < count($this->myProtocol->ukNorm)) && (($test * (int) $this->myProtocol->ukNorm[$p]) > 0)) {
                if ((($test * (int) $this->myProtocol->ukNorm[$p]) > 0))
                  {
                    if (abs($test) < abs($this->myProtocol->ukNorm[$p]) && !$this->testTime($test, $this->myProtocol->ukNorm[$p]))
                      {
  //                      echo ' duration2raw REDUCED duration @' . $p . ' required = ' . $test . ' Found = ' . $this->myProtocol->ukNorm[$p] . ' <br>';
                        $this->myProtocol->ukNorm[$p] -= $test;
                        $this->myProtocol->ukExtra += abs($test);
                      } //abs($test) < abs($this->myProtocol->ukNorm[$p])
                    else
                      {
   //                     echo ' duration2raw SKIP duration @' . $p . ' required = ' . $test . ' Found = ' . $this->myProtocol->ukNorm[$p] . ' <br>';
                        $this->myProtocol->ukptr++;
                      }
                  } //($test * (int) $this->myProtocol->ukNorm[$p]) > 0
              }
          } //$this->myProtocol->isDecode()
        if (!$this->myProtocol->isOutputRaw())
            return $result;
        // adds to result this duration     
        return (ltrim($result . irp_protocol::CHAR_LIST . $this->toRaw($exp), irp_protocol::CHAR_LIST));
      }
    //
    // decode $number bit, updates $this->myProtocol->ukptr;
    // return FALSE on error
    // 
    private function decodeBit($number)
      {
        if ($this->myProtocol->errPosition > 0)
            return false;
        $result = '';
        $start  = $this->myProtocol->ukptr;
        for ($i = 0; $i < ceil($number / $this->bitCoded); $i++) // for coding units required
          {
            $tcoded = '';
            $y      = -5;
            $code   = 0;
            $found  = true;
            //  
            foreach ($this->normCode as $testc) // for all bits combinations (2,4, 8, 16)
              {
                $tot   = count($testc); // get durations
                $found = true;
                $less  = false;
                $k     = $this->myProtocol->ukptr;
                for ($j = 0; $j < $tot; $j++)
                  {
                    if (!isset($this->myProtocol->ukNorm[$k + $j]))
                      {
                        $found = false;
                        break;
                      }
                    $test  = $testc[$j];
                    $found = $found && $this->testTime($test, $this->myProtocol->ukNorm[$k + $j]); // test standard?
  //              echo ' decodeBit exact-test '.$code.'['.$j.'] test @['.($k+$j).']= '. $this->myProtocol->ukNorm[$k+$j].' required '.$test.' found: '.(($found)?'true':'false').'<br>';
                    if (!$found && ($test * $this->myProtocol->ukNorm[$k + $j] > 0)) // not found and same sign
                      {
 //  conditions for test-less
                     if (($tot == 1) || //only one value per code, like asynchronous
//						($j == $tot - 1)   // LAST BIT, ANY CASE
// last bit of a cycle, not total
                 (abs($this->myProtocol->ukNorm[$k + $j]) > 5 * $this->getMaxCodeTime()) ||  // long duration
                 (($this->encoding == self::PHASE_SHIFT) && ($j == $tot - 1))  || // last bit in phase-shift
                 (($this->encoding == self::UNKNOWN) && ($j == $tot - 1))   // last bit in unknown protocols
				 || (($k + $j +1) == count($this->myProtocol->ukNorm) )  // last test, any case
	//					 (abs($this->myProtocol->ukNorm[$k + $j]) > 5 * $this->getMaxCodeTime())
  //                          (abs($this->myProtocol->ukNorm[$k + $tot - 1]) > 5 * $this->getMaxCodeTime()) // if very long duration
                            )
                          {
                            $less  = (abs($test) < abs($this->myProtocol->ukNorm[$k + $j]));
                            $found = $less;
   //                   echo ' decodeBit less-test '.$code.' test ['.($k+$j).'] '. $this->myProtocol->ukNorm[$k+$j].' required '.$test.' found: '.(($less)?'true':'false').'<br>';
                          }
                      }
                    if (!$found)
                        break; // next code
                  } //$j = 0; $j < $tot; $j++
                // found - now adjust data  
                if ($found)
                  {
                    if ($less)
                      {
                        $testl = $testc[$tot - 1];
                        $this->myProtocol->ukNorm[$k + $tot - 1] -= $test;
                        $this->myProtocol->ukExtra += abs($test);
                        $this->myProtocol->ukptr += $tot - 1;
                        $y = $code;
 //                       echo ' decodeBit set less = '.decbin($y).'<br>';                      
                        break; // done
                      }
                    if (!$less)
                      {
                        $this->myProtocol->ukptr += $tot;
                        $y = $code; // done
 //                       echo ' decodeBit set exact = '.decbin($y).'<br>';                     
                        break;
                      }
                  }
                $code++; // else more test   
              } //$this->codeBit as $testc
			if ($y >= 0) {
	           $tmp = '00000000' . decbin($y);
               $tmp = substr($tmp, -$this->bitCoded);
               $result .= $tmp;
			   }
	
          } //$i = 0; $i < ceil($target / $this->bitCoded); $i++
 //       if ($start == $this->myProtocol->ukptr)
          if (($result == ''))
          {
            /*        
            echo "error 5 <br>";
            if ($this->myProtocol->errPosition < 0)
            $this->myProtocol->errPosition = $this->myProtocol->ukptr;
            echo 'decodeBit ERROR decode pos '.($this->myProtocol->ukptr + 1).'<br>';                    
            // echo 'decodeBit Decode bad = '.$result.'<br>'; 
            */
            return false;
          }
 //       echo 'Decode ok = '.$result.'<br>';                   
        return $result;
      }
    /* 
     * Adds $min bits to bitCoded, decoding RAW (decode phase)
     *
     */
    private function raw2decoded($min)
      {
        if ($this->myProtocol->ukptr >= count($this->myProtocol->ukNorm))
          {
     //       echo "error 1 <br>";
     //       if ($this->myProtocol->errPosition < 0)
     //           $this->myProtocol->errPosition = $this->myProtocol->ukptr;
            return -1;
          }
/*		  
       // skips durations (if any) 
       while (abs($this->myProtocol->ukNorm[$this->myProtocol->ukptr]) > 4 * $this->getMaxCodeTime())
          {
           echo ' raw2decoded SKIP duration @' . $this->myProtocol->ukptr . ' Found = ' . $this->myProtocol->ukNorm[$this->myProtocol->ukptr] . ' <br>';       
            $this->myProtocol->ukptr++;
            if ($this->myProtocol->ukptr >= count($this->myProtocol->ukNorm))
              {
                $this->myProtocol->ukptr = count($this->myProtocol->ukNorm) - 1;
                
                return -1; // last value
              }
  */
            /*           
            {
            echo "error 2 <br>";
            if ($this->myProtocol->errPosition < 0)
            $this->myProtocol->errPosition = $this->myProtocol->ukptr;
            return -1;
            }
            */
 //         } //abs($this->myProtocol->ukNorm[$this->myProtocol->ukptr]) > 4 * $this->getMaxCodeTime()
        if ($min > 0)
          {
            $target = ($this->myProtocol->decodePtr + $min - strlen($this->myProtocol->bitDecoded));
            if ($target < 0)
                return -1;
            $data = $this->decodeBit($target);
            if ($data === false) ;
        //              $data = substr('0000000000000000', -$target);
//    echo 'raw2decoded request '.$target.' bits found:'.$data.' <br>';     
            $this->myProtocol->bitDecoded .= $data;
            return -1;
          }
        else // $min = 0
          {
            $data = '';
            $n    = 0;
            while (($x = $this->decodeBit(1)) !== false)
              {
                $data .= $x;
                $n++;
              }
            $this->myProtocol->errPosition = -1;
            $this->myProtocol->bitDecoded .= $data;
            return $n;
          }
      }
    // used by raw2decoded and duration2raw
    private function testTime($test, $value)
      {
        // return true/false
        // absolute time tolerance = +/-  deltaTime /TIME_FACTOR_DELTA
        if (($test == 0) || ($value == 0))
            return false;
        $uplimit = $test + ($this->myProtocol->deltaTime / irp_protocol::TIME_FACTOR_DELTA);
        $lolimit = $test - ($this->myProtocol->deltaTime / irp_protocol::TIME_FACTOR_DELTA);
        return (($value * $test > 0) && ($value <= $uplimit) && ($value >= $lolimit));
      }
    /*
     * Adds/gets $size bits for $value to output/input stream (encoding/decoding phase)
     * (really it puts bits in a bitbuffer for late codification)
     * &smode = 'raw'  =>  523|-523|523  // raw output
     * &smode = 'bit'  =>  100101        // bit output
     * &smode = 'code' =>  2|-1|2        // output  for codeBit (special internal)
     */
    public function toStream($value, $size, $smode)
      {
	    $reverse = false;
        if ($size == '')
            $size = 31;
	    if ($size < 0)	{
		    $size = abs($size);
			$reverse = true;
	    }
 // echo  "toStream($value, $size, $smode) ";
        $result = '';
        if (!($this->myProtocol->isDecode() || ($smode == 'code')))
          {
            // ENCODE:  stores data to buffer, for late coding    
            if ($this->myProtocol->isInputRaw())
              {
                // data from processing IRP + values
                $tmp = '000000000000000000000000000000000' . decbin($value);
                $this->bitbuffer .= substr($tmp, -$size);
              } //$this->myProtocol->isInputRaw()
            else
              {
			  
                // data from HEX data string
				 $tmp = $this->myProtocol->getBits($size);
//		echo  "gets $tmp  ";
         		if ($reverse){
				     $tmp = strrev($tmp);   // like bitfield2int, F:-4 reverses bit order
				 }
	// removed, test case Kathrein			 
	//			 if ($value != 0){
	//			     $tmp = '000000000000000000000000000000000' . decbin($value);
    //                 $tmp = substr($tmp, -$size);
	//			 }
				 
 // echo  " == $tmp <br> ";
               $this->bitbuffer .= $tmp;
              }
            return false;
          } //!($this->myProtocol->isDecode() || ($smode == 'code'))
 
    if ($smode == 'code')
          {
            // for coding recursion =>  2|-1|2 (immediate output  for normCode-internal use)
            for ($i = 0; $i < ($size / $this->bitCoded); $i++)
              {
                $tcoded = '';
                $y      = $value & $this->myProtocol->BMASK[$this->bitCoded];
                $value  = $value >> $this->bitCoded;
                foreach ($this->codeBit[$y] as $bit)
                  {
                    $tcoded .= irp_protocol::CHAR_LIST . $bit;
                  } //$this->normCode[$y] as $bit
                $result = $tcoded . $result;
              } //$i = 0; $i < ($size / $this->bitCoded); $i++
            $result = str_replace(',', irp_protocol::CHAR_LIST, $result);
            //   echo ' = '.ltrim($result, irp_protocol::CHAR_LIST). '<br>';  
            return ltrim($result, irp_protocol::CHAR_LIST);
          } //$smode == 'code'
        
		//========== decode:    
        $w = $this->raw2decoded($size); // push bits in FIFO
        if ($w > 0)
          {
            $size = $w;
           //         echo ' return size '.$w.'<br>';
          }
 //   echo ' toStream bit '.$size.' from '.$this->myProtocol->decodePtr.' data = '.$this->myProtocol->bitDecoded.'<br>';            
        $tmp = substr($this->myProtocol->bitDecoded, $this->myProtocol->decodePtr, $size); // pop only bits required
        $this->myProtocol->decodePtr += $size;
        if ($this->myProtocol->order == 'lsb')
          {
            $tmp = strrev($tmp); // reverse bits    
          } //$this->myProtocol->order == 'lsb'
        // stores result in  dataDecoded  
		if (isset($this->myProtocol->dataDecoded[$this->myProtocol->ukdata])){
	//	    $this->myProtocol->dataDecoded[$this->myProtocol->ukdata] += 256*bindec($tmp);
		} else {
			$this->myProtocol->dataDecoded[$this->myProtocol->ukdata] = bindec($tmp);
			if (($w > 0) && ($this->myProtocol->uk2data != ''))
			  {
				$this->myProtocol->dataDecoded[$this->myProtocol->uk2data] = $w;
//		 echo 'DECODED special :X '.$this->myProtocol->uk2data.' => '.$w.'<br>';
			  }
//	  echo 'DECODED '.$this->myProtocol->ukdata.' => '.$tmp.' = '.bindec($tmp).'<br>';
		  }
	  return false;
      }
    /*
     * for numeric bitfield like  '6:4:2'
     * returns int
     */
    public function bitfield2int($value, $reverse)
      {
        if (!strpos($value, ':'))
            return false;
        // echo 'bitfield2int = '.$value;   
        $parts     = split('[:]', $value . ':0');
        $result    = '';
        $reverseit = false;
        // processing size   
        if ($parts[1] == '') // case D::4  
          {
            $size = '31'; //infinite
          } //$parts[1] == ''
        else if ($parts[1][0] == '-') // case D:-4: 
          {
            $size      = ltrim($parts[1], '-');
            $reverseit = true;
          } //$parts[1][0] == '-'
        else
            $size = $parts[1];
        // cut bits   
        $x = $parts[0] >> $parts[2];
        $x &= $this->myProtocol->BMASK[$size];
        if ($reverse ^ $reverseit)
          {
            $data = '00000000000000000000000000000000' . decbin($x);
            $data = substr($data, -$size);
            $data = strrev($data); // reverse bits    
            $x    = bindec($data);
          } //$reverse ^ $reverseit
        //  echo ' =>  '.$x.'<br>';  
        return $x;
      }
    /*
     * for numeric bitfield   6:4:2
     * returns raw/bits 
     */
    public function bitfield2raw($value)
      {
	    $parts = explode(':', $value . ':0');
        $x     = $this->bitfield2int($value, $this->myProtocol->order == 'lsb');	
// echo "bitfield2raw($value) == $x <br>";		
        return $this->toStream($x, $parts[1], $this->myProtocol->getOutMode());
      }
  } // ends irp_bitSpec class
/*
 * Aux class, for bitStream
 *
 * private internal class
 * stores informations about bitStream (data coding rules)
 * Methods to create/decode IRP bitStream included repetitions/dittos
 * recursive
 */
class irp_bitStream
  {
    private $myProtocol = null;
    private $myBitSpec = null;
    //
    public $IRStream = '';
    private $thisPass = 1;
    private $nPass = 0;
    private $rrepeat = '';
    private $tmpBuffer = '';
    private $outptr = 0;
    private $deep = 0;
    //    
    static $count = 0;
    //-----------   
    // the irp_protocol, the irp_bitSpec, the bitstream string ((...))
    function __construct($irp, $bitspec, $bitstream, $rrepeat, $level = 0)
      {
        $this->myProtocol = $irp;
        $this->myBitSpec  = $bitspec;
        $this->nPass      = $rrepeat;
        $this->thisPass   = 1;
        $this->outptr     = 0;
        $count            = 0;
        $this->deep       = $level;
        // processes $bitstream  
        $tmp              = trim($bitstream);
        $last             = strlen($tmp) - 1;
        // rrepeat: case )+, )*, )3
        if (($tmp[$last] != ')') && ($tmp[$last - 1] == ')'))
          {
            $this->rrepeat = $tmp[$last];
            $tmp[$last]    = ' ';
          } //($tmp[$last] != ')') && ($tmp[$last - 1] == ')')
        // rrepeat: case  )3+
        if (($tmp[$last] == '+') && ($tmp[$last - 2] == ')'))
          {
            $this->rrepeat  = substr($tmp, $last - 1);
            $tmp[$last]     = ' ';
            $tmp[$last - 1] = ' ';
          } //($tmp[$last] == '+') && (ctype_digit($tmp[$last - 1])) && ($tmp[$last - 2] == ')')
        $tmp = trim($tmp);
        if (irp_getRMatchBrace($tmp, '(', 0, ')') === false)
          {
            echo "*** ERROR: not a valid IRstream (check IRP):'$tmp'<br>";
            exit;
          }
        $this->IRStream = irp_onion($tmp, '(', ')');
        // echo 'new bitStream: ['. $bitstream.'] >> '.$this->IRStream.'<br>'; 
      }
    // ------------------ functions for parse/eval(expressions): return numeric
    // utilities to handle expressions
    // $start is to left of operation sign
    //  example:  (A:2)**2
    //                ^
    private function getLeftOperand($text, $start)
      {
        $e = $start;
        //   for number10, number16, vars:
        if (ctype_alnum($text[$e]))
          {
            for (; ($e > 0) and (ctype_alnum($text[$e - 1])); $e--);
          } //ctype_alnum($text[$e])
        else
        // for expressions (..)   
            if ($text[$e] == ')')
          {
            $e = irp_getLMatchBrace($text, '(', $e, ')');
          } //$text[$e] == ')'
        if ($e === false)
            return false;
        // unary operators
        if (($e - 1 >= 0) && ($text[$e - 1] == '-'))
            $e--;
        if (($e - 1 >= 0) && ($text[$e - 1] == '~'))
            $e--;
        if (($e - 1 >= 0) && ($text[$e - 1] == '#'))
            $e--;
        return $e;
      }
    // $start is to right of operation sign
    //  example:  (A:2)**2
    //                   ^
    private function getRightOperand($text, $start)
      {
        $e   = $start;
        $max = strlen($text) - 1;
        if ($e > $max)
            return false;
        // unary operators
        if ($text[$e] == '-')
            $e++;
        if ($text[$e] == '~')
            $e++;
        if ($text[$e] == '#')
            $e++;
        //   for number10, number16, vars:
        if (ctype_alnum($text[$e]))
          {
            for (; ($e < $max) and (ctype_alnum($text[$e + 1])); $e++);
            return $e;
          } //ctype_alnum($text[$e])
        // for expressions (..)   
        if ($text[$e] == '(')
          {
            $e = irp_getRMatchBrace($text, '(', $e, ')');
            if ($e !== false)
                return $e;
            ;
          } //$text[$e] == '('
        return false;
      }
    // cuts a substring with a full bitField x:y:z  (A+b):y:z (with expressions)
    private function parseBitField(&$text)
      {
        $p = strpos($text, ':');
        if ($p === false)
            return false;
        $s = $this->getLeftOperand($text, $p - 1);
        if ($s === false)
            return false;
        if ($text[$p + 1] == ':')
            $p++; // case A::4
        $e = $this->getRightOperand($text, $p + 1);
        if (($e + 1 < strlen($text)) && ($text[$e + 1] == ':')) // case A:2:3
          {
            $e++;
            if ($e + 1 < strlen($text))
                $e = $this->getRightOperand($text, $e + 1);
          } //($e + 1 < strlen($text)) && ($text[$e + 1] == ':')
        if ($e === false)
            return false;
        // 
        $tmp = substr($text, $s, $e - $s + 1);
        // exception, unary minus affect all bitfield: -A:B  => -(A:B)
        //  but not in this case -(a):B		
        if (($tmp[0] == '-') && ($tmp[1] != '('))
          {
            $tmp = ltrim($tmp, '-');
            $s++;
          }
        $text[$s] = irp_protocol::CHAR_EXTRA; // to get the true position in replace
        return $tmp;
      }
    // local,  transforms expressions(if any) to numbers
    private function evalBitField($bitField)
      {
   //     echo 'evalbitField: '.$bitField;
        // first operand  
        $e  = $this->getRightOperand($bitField, 0);
        $v1 = $this->evalExp(substr($bitField, 0, $e + 1));
        // second operand -> first:second..
        //                         ^ $s1
        $s1 = $e + 2; //(
        //
        if (isset($bitField[$s1]) && $bitField[$s1] == ':')
          {
            $v2 = '16'; // case 4::2
            $e  = $s1 + 1;
          } //$bitField[$s1] == ':'
        else
          {
            $e  = $this->getRightOperand($bitField, $s1);
            $e2 = substr($bitField, $s1, $e - $s1 + 1);
            $v2 = $this->evalExp(substr($bitField, $s1, $e - $s1 + 1));
            if (($v2 == 0) && ctype_alpha($e2) && (!isset($this->myProtocol->environ[$e2])))
              {
                //   echo ' UNSET var '.$e2.'<br>';         
                $this->myProtocol->uk2data = $e2;
              }
            else
              {
                $this->myProtocol->uk2data = '';
              }
          }
        // last operand -> first:second:last
        //                             ^ $s2
        $s2 = $e + 1;
        if (($s2 < strlen($bitField)) && ($bitField[$s2] == ':'))
          {
            $e   = $this->getRightOperand($bitField, $s2 + 1);
            $v3  = $this->evalExp(substr($bitField, $s2 + 1, $e - $s2));
            $tmp = $v1 . ':' . $v2 . ':' . $v3;
          } //($s2 < strlen($bitField)) && ($bitField[$s2] == ':')
        else
          {
            $tmp = $v1 . ':' . $v2;
          }
 //       echo ' => '.$tmp.'<br>';    
        return $tmp; // numeric bitfield like 8:4:2
      }
    /*
     *for numeric/variabile/expression bitfield  A:4:2, returns numeric
     */
    private function evalBitField2int($bitField)
      {
        return $this->myBitSpec->bitfield2int($this->evalBitField($bitField), false);
      }
    // cuts a substring with a power (x**y) 
    private function parsePow($text)
      {
        $p = strpos($text, '**');
        if ($p === false)
            return false;
        $s = $this->getLeftOperand($text, $p - 1);
        if ($s === false)
            return false;
        $e = $this->getRightOperand($text, $p + 2);
        if ($e === false)
            return false;
        return substr($text, $s, $e - $s + 1);
      }
    // cuts a substring with a variable like A  OEM1 (upper/lover case)
    private function parseVars(&$text)
      {
        $s = -1;
        while ($s < strlen($text) - 1)
          {
            if (ctype_alpha($text[++$s]))
              {
                if (($s - 1 >= 0) && (($text[$s] == 'x') || ($text[$s] == 'X')) && ($text[$s - 1] == '0'))
                    continue;
                $l = 0;
                for (; ($s + $l < strlen($text)) && ctype_alnum($text[$s + $l]); $l++);
                $tmp = substr($text, $s, $l);
                if (!(isset($this->myProtocol->environ[$tmp]) || isset($this->myProtocol->environ['_def_'][$tmp])))
                  {
                    if ($this->myProtocol->isInputRaw() && !$this->myProtocol->isDecode())
                      {
                        if (!$this->myProtocol->doDataPermanence)
                            echo '+++ WARNING: Variable [' . $tmp . '] not found! <br>';
                        //               $s += $l;
                        //               continue;      
                      } //$this->myProtocol->isInputRaw() && !$this->myProtocol->isDecode()
                  } //!(isset($this->myProtocol->environ[$tmp]) || isset($this->myProtocol->environ['_def_'][$tmp]))
                //ok, found
                $text[$s] = irp_protocol::CHAR_EXTRA; // to get the true position in replace
                //    echo "found $tmp <br>";
                return $tmp; // string with variable
              } //ctype_alpha($text[++$s])
          } // ends while
        return false;
      }
    // eval a variable only, given name
    private function evalVars($name)
      {
        if ($this->myProtocol->isInputRaw() && isset($this->myProtocol->environ[$name]))
          {
            // echo 'gets '.$name.' from environ <br>';        
            $value = $this->myProtocol->environ[$name];
          } //isset($this->myProtocol->environ[$name])
        else if ($this->myProtocol->isInputRaw() && isset($this->myProtocol->environ['_def_'][$name]))
          {
            // echo 'gets '.$name.' from expression <br>'; 
            if ($this->count++ > 500)
                die('*** INTERNAL ERROR - infinite recursion >>> check expressions in IRP<br>');
            $value = $this->evalExp($this->myProtocol->environ['_def_'][$name]); // recursion
          } //isset($this->myProtocol->environ['_def_'][$name])
        else
          {
            if ($this->myProtocol->isInputRaw() && !$this->myProtocol->isDecode())
                if (!$this->myProtocol->doDataPermanence)
                    echo '+++ WARNING: var [' . $name . '] not set! (uses default 0) <br>';
            $value = '0';
          }
        // echo ' a var '.$name.' => '.$value.'<br>';		
        return $value;
      }
    // cuts a substring with a bit-count operator like #(A+B)
    private function parseBitCount($text)
      {
        $s = strpos($text, '#');
        if ($s === false)
            return false;
        $e = $this->getRightOperand($text, $s + 1);
        if ($e === false)
            return false;
        return substr($text, $s, $e - $s + 1);
      }
    /*
     * eval any expression
     * descendent recursive
     * when the expression is all numeric and with only standard php operators uses php 'eval()'
     * returns a number.
     */
    public function evalExp($exp)
      {
        // fast test: it is a number10 or (number10)? (common case)
        if (is_int($exp))
            return $exp;
        if (is_string($exp))
          {
            $tmp = irp_onion($exp, '(', ')');
            if (ctype_digit($tmp) || (($tmp[0] == '-') && ctype_digit(substr($tmp, 1))))
                return (int) $tmp;
          } //is_string($exp)
        // real expression  
        //   echo ' expression : '.$exp;
        $tmp = $exp;
        // replaces vars with numerical values: A with environ[A]
        while ($name = $this->parseVars($tmp))
          {
            $value   = $this->evalVars($name);
            //   echo '<br>   VAR : '.$name.' >> '. $tmp;
            // parseVars replaces first char of Var name in expression with irp_protocol::CHAR_EXTRA
            // to allow the use of str_replace			
            $name[0] = irp_protocol::CHAR_EXTRA;
            $tmp     = str_replace($name, '(' . $value . ')', $tmp);
          } //$name = $this->parseVars($tmp)
        //  special operation bitcount: replaces #A or #(A^B) with num
        while ($aVar = $this->parseBitCount($tmp))
          {
            //              echo '<br> BitCount: '.$aVar ;
            $bitVar = ltrim($aVar, '#');
            $result = $this->evalExp($bitVar); // recursion
            // now counts, max 16 bit       
            $nbit   = 0;
            $xmask  = 1;
            for ($i = 0; $i < 16; $i++)
              {
                if (($result & $xmask) != 0)
                    $nbit++;
                $xmask <<= 1;
              } //$i = 0; $i < 16; $i++
            //      echo ' >> '.$nbit;      
            $tmp = str_replace($aVar, $nbit, $tmp);
          } //$aVar = $this->parseBitCount($tmp)
        // special operation bitfield: replaces with num, no vars
        while ($original = $this->parseBitField($tmp))
          {
            $calc        = $this->evalBitField2int($original);
            // echo '<br> parse-bitfield : '.$original . ' >> '. $calc.'<br>';
            $original[0] = irp_protocol::CHAR_EXTRA;
            $tmp         = str_replace($original, $calc, $tmp);
          } //$original = $this->parseBitField($tmp)
        //special operation, power, changes notation: replaces A**B with pow(A,B)  
        while ($power = $this->parsePow($tmp))
          {
            //    echo '<br> power : '.$power;
            $tmp = str_replace('**', ',', $power);
            //    echo ' >> pow('.$tmp.')';
            $tmp = str_replace($power, 'pow(' . $tmp . ')', $tmp);
          } //$power = $this->parsePow($tmp)
        // now numeric, standard php expression: uses eval()
        //      echo ' ->> '.$tmp ;
        if ('' . $tmp == '') // in case of $tmp = 0, $tmp == '' is true.
          {
            echo "+++ WARNING: eval empty expression. Was ($exp) <br>";
            $tmp = 0;
          } //'' . $tmp == ''
        else
          {
            $tmp = eval('return ' . $tmp . ';');
          }
        //         echo ' = '.$tmp .'<br>' ;
        return $tmp;
      }
    // -------------------------------- functions to evaluate bitStream items 
    /*
     * for durations. $long is number 
     */
    private function evalDuration2raw($long)
      {
        $val = $this->myBitSpec->duration2raw($long);
        return ($val);
      }
    /*
     *for numeric/variabile/expression bitfield  A:4:2
     */
    private function evalBitField2raw($bitField)
      {
        $p   = strpos($bitField, ':');
        $q   = strrpos($bitField, ':');
        $var = substr($bitField, 0, $p);
        if ((!ctype_alpha($var)) || ($p != $q)){
            $vField = $this->evalBitField($bitField);
			$r   = strpos($vField, ':');
            $this->myProtocol->ukdata = $var.substr($vField,$r); // simple vars
			}
        else
            $this->myProtocol->ukdata = substr($bitField, 0, $p); // expressions bitfield

		return $this->myBitSpec->bitfield2raw($this->evalBitField($bitField));
      }
    // processes variations
    // return item or false
    private function evalVariations($variations)
      {
        $vari = explode(']', trim($variations));
        // echo 'found variation '.$variations.' =>'; print_r($vari); echo '<br>';
        $tmp  = '';
        if ($this->thisPass == 1)
          {
            $tmp = ltrim($vari[0], '[');
            //            echo 'Variation = 1 <br>';            
          } //$this->thisPass == 1
        else if ($this->thisPass > 1)
          {
            if (($this->thisPass == $this->nPass) && (isset($vari[2]) && ($vari[2] != '')))
              {
                $tmp = ltrim($vari[2], '[');
                //                echo 'Variation = 3 <br>';   
              } //($this->thisPass == $this->nPass) && (isset($vari[2]) && ($vari[2] != ''))
            else
              {
                $tmp = ltrim($vari[1], '[');
                //                 echo 'Variation = 2 <br>';   
              }
          } //$this->thisPass > 1
        if (trim($tmp) == '')
            return false; // case []
        return $tmp; // to be re-evaluated as item
      }
    // add vars like A=54
    private function evalAssign($exp)
      {
        //  $this->myProtocol->setValues($exp);
        $tmp                                      = explode('=', $exp);
        $val                                      = $this->evalExp($tmp[1]);
        $this->myProtocol->environ[trim($tmp[0])] = $val;
        //  echo '  ASSIGN VAR :'.$tmp[0].' = '. $val.'<br>';
      }
    // add to output buffer data RAW|BIN (used by getRaw() )
    private function appendBuffer($result)
      {
        if ($result === false)
            return;
        if (strlen($result) == 0)
            return;
        if ($this->myProtocol->isOutputRaw())
          {
            $tmp = ltrim($result, irp_protocol::CHAR_LIST); // just in case
            $this->tmpBuffer .= irp_protocol::CHAR_LIST . $tmp; // add to result
            // echo 'AppendBuffer @['.$this->myProtocol->ukptr.'] data: '.$result.' sum = '.$this->tmpTime.'<br>';				  
          } //$this->myProtocol->isOutputRaw()
        else
            $this->tmpBuffer .= trim($result); // add result, BIN
        return;
      }
    // 
    // get one pass RAW|BIT|DECODE data for a full IRstream
    //
    public function getRaw()
      {
        // sets vars
        $this->tmpBuffer = ''; // start values
        $this->outptr    = 0;
        $this->tmpTime   = 0;
   //     echo 'processing IRstream: '.$this->IRStream.'<br>';
        $items           = explode(',', $this->IRStream);
        $reend           = count($items);
        // processes IRstream 
        for ($index = 0; $index < $reend; $index++)
          {
            //	    if ($this->myProtocol->isError()) break;
            $item = trim($items[$index]);
          // echo ' Buffer: '.$this->tmpBuffer.' <br>';
  //       echo ' processing item: '.$item.'<br>';
            // variations: [T=1][T=0]
            if ($item[0] == '[')
              {
                $item = $this->evalVariations($item); // returns correct T=2 or false
                if ($item === false)
                  {
                    $reend = 0; // case [], abort
                    continue;
                  } //$item === false
              } // no continue here: must eval new $item
            //   IRstream: (x,y,..)
            if (($item[0] == '(') && (!irp_getRMatchBrace($item, '(', 0, ')'))){
                $tot = $item;
                while (!irp_getRMatchBrace($tot, '(', 0, ')'))
                  {
                    $tot .= ',' . $items[++$index];
                    if (($index + 1) >= count($items))
                        break;
                  }
                $tmp = $this->myBitSpec->flushBitBuffer();
                $this->appendBuffer($tmp);
                // recursive call        
                //              echo "recursive call of $tot <br>";
                $aStream = new irp_bitStream($this->myProtocol, $this->myBitSpec, $tot, $this->nPass, $this->deep + 1);
                $tmp     = $aStream->analyzeRaw();
                $this->appendBuffer($tmp);
                continue;
              } //($item[0] == '(')
            //   Bitspec followed by IRstream <a|b>(x,y,..)
            if ($item[0] == '<')
              {
                $tot = $item;
                while (!((strpos($tot, '(') !== false) && (irp_getRMatchBrace($tot, '(', strpos($tot, '('), ')') !== false)))
                  {
                    $tot .= ',' . $items[++$index];
                    if ($index >= (count($items) - 1))
                        break;
                  }
                // recursive call       
                $tmp = $this->myBitSpec->flushBitBuffer();
                $this->appendBuffer($tmp);
                //                   echo ' buffer before recursion  = {'. $this->tmpBuffer.'}<br>';              
                //    echo ' bitDecoded before recursion  = ['. $this->myProtocol->bitDecoded.']<br>';              
                $e        = irp_getRMatchBrace($tot, '<', 0, '>');
                // cuts '<data>(stream)       
                //            ^ $e
                $newBSpec = new irp_bitSpec($this->myProtocol, $this->myBitSpec, substr($tot, 0, $e + 1));
                $aStream  = new irp_bitStream($this->myProtocol, $newBSpec, substr($tot, $e + 1), $this->nPass, $this->deep + 1);
                $tmp      = $aStream->analyzeRaw();
                $this->appendBuffer($tmp);
                //                  echo ' buffer after = {'. $this->tmpBuffer.'}<br>'    ;           
                //   echo ' bitdecoded after = ['. $this->myProtocol->bitDecoded.']<br>'    ;           
                continue;
              } //$item[0] == '<'
            //    Assignment
            if (strpos($item, '='))
              {
                $this->evalAssign($item);
                continue;
              } //strpos($item, '=')
            //    Bitfield
            if (strpos($item, ':'))
              {
                $tmp = $this->evalBitField2raw($item);
  //       echo "bitfield $item = $tmp <br>";				
                if ($tmp === false)
                    continue;
                $this->appendBuffer($tmp);
                continue;
              } //strpos($item, ':')
            //    Extent
            if ($item[0] == '^')
              {
                $tmp = $this->myBitSpec->flushBitBuffer(); // flush all
                $this->appendBuffer($tmp);
                // 
                $ite     = ltrim($item, '^');
                $t       = $this->myBitSpec->toRaw($ite);
                $totTime = 0;
                //  sum now for totTime	
                if ($this->myProtocol->isDecode())
                  {
                    $totTime = $this->myProtocol->ukExtra;
                    $end     = $this->myProtocol->ukptr;
 //           echo " totTime = $totTime end = $end prtTime = ".$this->myProtocol->prtTime."<br>";
                   for ($k = $this->myProtocol->prtTime; $k < $end; $k++)
                        $totTime += abs($this->myProtocol->ukNorm[$k]);
                    //		 echo 'extent DECODE from '.$this->myProtocol->prtTime.' to '.($end-1);
                    $this->myProtocol->prtTime = $end + 1;
                    $this->myProtocol->ukExtra = 0;
 //            echo " totTime = $totTime  <br>";
                }
                else
                  {
                  $times = explode(irp_protocol::CHAR_LIST, $this->tmpBuffer);
                    $end   = count($times);
                       if ($this->myProtocol->isOutputRaw())
                        for ($k = $this->outptr; $k < $end; $k++)
                            $totTime += abs($times[$k]);
                    //		 echo 'extent ENCODE from '.$this->outptr.' to '.($end-1);
                    $this->outptr = $end + 1;
                  }
           if (($t <= $totTime) && $this->myProtocol->isOutputRaw())
                  {
                   echo "+++ WARNING: Extent ($item) less than actual time ($totTime) !! extended <br>";
                    $t = $totTime + 10000;
                  }
                $diff = $totTime - $t;
                if ($this->myProtocol->isDecode())
                  {
                    $p   = $this->myProtocol->ukptr;
                    $dim = $this->evalDuration2raw($diff . 'u');
                    if ($p < count($this->myProtocol->ukNorm))
                      {
                        $this->myProtocol->ukExtra = abs($this->myProtocol->ukNorm[$p]); // special for extent
                      }
                  }
                else
                  {
                    $dim = $this->evalDuration2raw($diff . 'u');
                  }
                $this->appendBuffer($dim);
                //	     echo ' total = '.$t.' Cum_time = '.$totTime.' diff = '.$diff.'<br>';				
                continue;
              } //$item[0] == '^'
            // in case of error			
            if ($this->myProtocol->errPosition > 0)
                break;
            // default: must be Duration  
            $max  = strlen($item) - 1;
            $calc = $item;
            switch ($item[$max])
            {
                case 'p':
                    $calc = rtrim($item, 'p');
                    break;
                case 'u':
                    $calc = rtrim($item, 'u');
                    break;
                case 'm':
                    $calc = rtrim($item, 'm');
                    break;
            } //$item[$max]
            // eval expression			
            $num = $this->evalExp($calc); // variable|numeric   
            if ($num === false)
              {
                echo '+++ WARNING: UNKNOWN bitStream item : [' . $item . ']<br>';
                continue;
              } //$num === false
            // replaces duration mark to numeric value		
            switch ($item[$max])
            {
                case 'p':
                    $num .= 'p';
                    break;
                case 'u':
                    $num .= 'u';
                    break;
                case 'm':
                    $num .= 'm';
                    break;
            } //$item[$max]
            $result = $this->evalDuration2raw($num);
            $this->appendBuffer($result);
            //       continue;          
          } //$index = 0; $index < $reend; $index++
        $tmp = $this->myBitSpec->flushBitBuffer(); // just in case
        $this->appendBuffer($tmp);
        $toSend = ltrim($this->tmpBuffer, irp_protocol::CHAR_LIST);
 //  echo "recursion done: $toSend <br>";
        return $toSend;
      }
    
    // returns correct repeat number      
    private function getRepeatValue($nuser)
      {
        // factors: 1) IRP
        //          2) user request
        $nirp  = 1;
        $level = $this->deep;
        $force = false;
        
        if ($this->rrepeat == '*')
            $nirp = $nuser - 1;
        else if ($this->rrepeat == '+')
            $nirp = $nuser;
        else
          {
            if (isset($this->rrepeat[1]) && $this->rrepeat[1] == '+')
              {
                $nx = substr($this->rrepeat, 0, 1);
              }
            else
              {
                $nx    = $this->rrepeat;
                $force = true;
              }
            if (ctype_digit($nx))
                $nirp = (int) $nx;
            if (ctype_alpha($nx))
              {
                if ($this->myProtocol->isDecode())
                  {
                    if (isset($this->myProtocol->dataDecoded[$nx])) {
					    $nirp = $this->myProtocol->dataDecoded[$nx];
                     } else {
					    $nirp = 1;
	                 }                  
                  }
                else
                  {
                    $nirp = $this->evalVars($nx);
                  }
              }
          }
    //    echo " nuser = $nuser, level =  ".$this->deep.", this->rrepeat =[".$this->rrepeat."], nirp = $nirp, force = $force  <br>"  ;       
        //------------------------
        if ($force)
            return $nirp;
        if ($level > 0)
            return $nirp;
        if ($nuser > $nirp)
            return $nuser;
        return $nirp;
      }
    
    
    
    //
    // main function to process bitStream
    // rrepeat calls to getRaw() as required by user and by protocol rules
    //
    public function analyzeRaw()
      {
        $LIMITPASS                 = 20; // just in case, limit
        $rawData                   = '';
        $this->thisPass            = 1;
        $this->myProtocol->ukExtra = 0; // zero only at decode start
        $more                      = false;
        $storedecode               = array();
		$startBitPtr               = $this->myProtocol->bitptr;
        //               echo "enter Analise level ".$this->deep."<br>";
        do
          {
            $more              = false;
			$this->myProtocol->bitptr = $startBitPtr;
            $this->myProtocol->ukExtra = 0; // zero only at decode start
           // echo '  pass '.$this->thisPass.'/' .$this->nPass.'<br>';       
            $this->storedecode = $this->myProtocol->dataDecoded;
            if ($this->myProtocol->isOutputRaw())
              {
                $data = $this->getRaw();
                if ($this->getRepeatValue($this->nPass) > 0)
                  {
                    $rawData .= irp_protocol::CHAR_LIST . $data;
                  }
                else
                  {
                    $this->myProtocol->dataDecoded = $this->storedecode;
   //                 echo "kill data decoded raw<br>";
                  }
                //         echo 'dataRAW= ['.$rawData.']<br>';				
              }
            else
              {
                $data = $this->getRaw();
                if ($this->getRepeatValue($this->nPass) > 0)
                  {
                    if ($this->deep == 0)
                      {
                        $rawData .= '  ' . $data;
                        
                        $this->myProtocol->decodePtr = strlen($this->myProtocol->bitDecoded); // just in case
                        if (($this->myProtocol->decodePtr > 0) && $this->myProtocol->bitDecoded[$this->myProtocol->decodePtr - 1] != ' ')
                          {
                            $this->myProtocol->bitDecoded .= '  ';
                            $this->myProtocol->decodePtr += 2;
                            //          echo 'dataBIN= ['.$this->myProtocol->bitDecoded.']<br>';		
                          }
                      }
                    else
                      {
                        $rawData .= $data;
                        
                      }
                  }
                else
                  {
                    $this->myProtocol->dataDecoded = $this->storedecode;
                    //              echo "kill data decoded bin<br>";
                  }
              }
          // echo 'Analize return = '.$rawData .'<br>';                    
            // case decode
          if ($this->myProtocol->isDecode() && ($this->getRepeatValue(12) > $this->thisPass) && ((count($this->myProtocol->ukNorm) - $this->myProtocol->ukptr) > 1))
               {
                $this->thisPass++;
                $more = true;
                $this->nPass++;
              }
            if (!$this->myProtocol->isDecode())
              {
                $this->thisPass++;
                $more = $this->getRepeatValue($this->nPass) >= $this->thisPass;
              }
   //   echo "  more => $more  <br>";    
            if ($this->thisPass == $LIMITPASS)
                echo "*** ERROR encode/decode loop never ends  <br>";
          } while ($more && $this->thisPass < $LIMITPASS);
        //  echo 'recursion done <br>';
        // clean up	bitDecoded, like trim($rawData);	
        $this->myProtocol->decodePtr = strlen($this->myProtocol->bitDecoded); // just in case
        while (($this->myProtocol->decodePtr > 0) && ($this->myProtocol->bitDecoded[$this->myProtocol->decodePtr - 1] == ' '))
            $this->myProtocol->decodePtr--;
        $this->myProtocol->bitDecoded = substr($this->myProtocol->bitDecoded, 0, $this->myProtocol->decodePtr);
        //   echo "exit Analise level ".$this->deep."<br>";
        if ($this->myProtocol->isOutputRaw())
            return ltrim($rawData, irp_protocol::CHAR_LIST);
        return trim($rawData);
      }
  } // ends irp_bitStream class
// ========================== AUXILIARY FUNCTIONS
/*
 * aux functions to find closing brace in expressions
 * ($text[$pos] == $braceOpen) 
 */
function irp_getRMatchBrace($text, $braceOpen, $pos, $braceClose)
  {
    if ($text[$pos] != $braceOpen)
        return false;
    $count = 0;
    for ($end = $pos; $end < strlen($text); $end++)
      {
        if ($text[$end] == $braceOpen)
            $count++;
        if ($text[$end] == $braceClose)
            $count--;
        if ($count == 0)
            return $end;
      } //$end = $pos; $end < strlen($text); $end++
    return false;
  }
// same but left 
//  ($text[$pos] == $braceClose)  
function irp_getLMatchBrace($text, $braceOpen, $pos, $braceClose)
  {
    if ($text[$pos] != $braceClose)
        return false;
    $count = 0;
    for ($end = $pos; $end >= 0; $end--)
      {
        if ($text[$end] == $braceClose)
            $count++;
        if ($text[$end] == $braceOpen)
            $count--;
        if ($count == 0)
            return $end;
      } //$end = $pos; $end >= 0; $end--
    return false;
  }
/*
 * aux function to eliminate '(..)', '<..>' etc.
 * returns the cut string
 */
function irp_onion($text, $lbrace, $rbrace)
  {
    $tmp = trim($text);
    if ($tmp[0] == $lbrace)
      {
        $max     = strlen($tmp) - 1;
        $closing = irp_getRMatchBrace($tmp, $lbrace, 0, $rbrace); // test
        if ($closing == $max)
          {
            $tmp[0]    = ' ';
            $tmp[$max] = ' ';
          } //$closing == $max
      } //$tmp[0] == $lbrace
    return trim($tmp);
  }
/*
 *  Aux function to process result from dataVerify(false)
 */
function irp_explodeVerify($result)
  {
    $tmp                 = array();
    $parts               = explode('|', $result);
    $tmp['dataProtocol'] = $parts[0];
    $tmp['dataDevice']   = $parts[1];
    $tmp['dataOK']       = $parts[2];
    return $tmp;
  }
/*
 *  Aux function to alterate RAW1
 *  returns a RawArray [khertz, base, count, raw]
 */
function irp_explodeRAW1($raw1)
  {
    $tmp          = array();
    $x            = strpos($raw1, '}');
    $info         = substr($raw1, 0, $x + 1);
    $tmp['raw']   = substr($raw1, $x + 1);
    //  echo " split: $info + $rawx <br>";	
    $info         = irp_onion($info, '{', '}');
    $datas        = explode(',', $info);
    $tmp['khertz'] = $datas[0];
    $tmp['base']  = $datas[1];
    $tmp['count'] = $datas[2];
    return $tmp;
  }
// inverse of irp_explodeRAW1()
function irp_implodeRAW1($rawArray)
  {
    return '{'.$rawArray['khertz'].','.$rawArray['base'] . ',' . $rawArray['count'] . '}' . $rawArray['raw'];
  }
  
// test to skip head and tail of RAW timings 
function irp_isInRange($i, $tot){
   return(($i > SKIPFIRST) && ( $i < $tot - SKIPLAST));
}
  
// calculates total time (skip first, last)  to use with RAW and RAW_0 (no compressed)
function irp_rawMicros($raw)
  {
    // excludes first and last times
    $sum   = 0;
    $times = explode('|', $raw);
	$tot = count($times);
	foreach($times as $i => $value){
	     if (irp_isInRange($i, $tot))
		       $sum += abs($value);
	}
    return $sum;
  }
?>
