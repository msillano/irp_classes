The irp_classes.pnp implements the core algorithms required for working with IR remote control, i.e. encode and decode RAW IR commands.
This is an implementation of 'IRP Execution Model' (see http://www.hifi-remote.com/wiki/index.php?title=IRP_Execution_Model) using php.
The  irp_classes.pnp contains also methods to handle IR strems without IRP, using 'learn and repeat' strategy.
It make easy to build IR applications whit data base and WEB interface.
In this dir, with irp_classes.pnp, you can found also 2 example applications:
 1) full-test, a test-case for irp_classes.php, showing all main features of the library using many IRP.
 2) decode-test, to decode IR data, recorded or live, using an Arduino IR receiver and a php serial extension.
A more complex example can be found in 'remoteDB', a demo application using MySQL database. (https://github.com/msillano/remoteDB)
-------------
Copy this directory in web area of your server: e.g. ' ...\apache\htdocs\www\phpIRPlib'.
------------- optional
If you have Arduino-uno and an IR receiver:
See the dir Arduino
------------- optional
Serial communications php-Arduino in windows:
Download and install 'PHP Serial extension' free from http://www.thebyteworks.com (with some limits).
-------------
note:
The 'decode-test' demo can run without Arduino IR HW and serial extension, using recorded IR data.
If you have some different IR HW, modify irp_rxtxArduino.php to receive RAW data from your HW.
