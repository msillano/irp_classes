/* 
 *  rawRxTx02
 *  Receive/transmit IR stream using "Serial php extension".
 *  
 *  Can be also tested standalone,  with 'serial monitor (@9600)': 
 *  when you see 'A'  you send: 
 *    'R' to receive RAW data (plain, RAW-0) from remote control 
 *       When red led on, Arduino is ready to receive IR from remote command
 *    'X' + RAW-1 (compressed IR stream) to transmit command to a device
 *    
 *    Uses 'IRlib2' (c) 2014-2017 by Chris Young  ()   
 */
 
// use IRLibRecvPCI 
#include <IRLibRecvPCI.h> 
#include <IRLibSendBase.h>    //We need the base code
#include <IRLib_HashRaw.h>    //Only use raw sender

IRrecvPCI myReceiver(2);//pin number for the receiver, TX 3 by default

IRsendRaw mySender;
  int status = 0;  // 0: wait, 1: TXdata, 2: TXsendIR, 3: RXwait
  int frequence ;
  int deltatime ;
  int datalen   ;
  int numpacks =2;
  int countpacks =0;

#define RAW_DATA_LEN 300
uint16_t rawData[RAW_DATA_LEN];
int index=0;

//
 void setup() {
  pinMode(LED_BUILTIN, OUTPUT);
  digitalWrite(LED_BUILTIN, LOW);
  myReceiver.setFrameTimeout(7000); 
//  myReceiver.enableAutoResume(rawData); 
  Serial.begin(9600);
  delay(2000); while (!Serial); //delay for Leonardo
  Serial.println(F("waiting user command..."));
  status = 0;
 }
/*
 */
   
void loop() {
  if (status == 0){ 
    digitalWrite(LED_BUILTIN, LOW);
    Serial.print("A");   // send an initial string
    delay(200);
    index = 0;
    while (Serial.available() > 0) {
        int inByte = Serial.read();
        if ( inByte == 'R') {
// R: receiving IR, from php program or serial monitor        
            myReceiver.enableIRIn(); // Start the receiver
            Serial.println("ready for rx ir"); 
            digitalWrite(LED_BUILTIN, HIGH);
            countpacks =0;
            status = 3;
            }
          if ( inByte == 'T') {
// T: transmitting IR, only from php program            
            frequence = Serial.parseInt();
            deltatime = Serial.parseInt();
            datalen = Serial.parseInt();
             status = 1;  
            }
          if ( inByte == 'X') {
//X: transmitting: only from serial monitor   
            Serial.println("send raw now");         
            while (Serial.available() < 4) delay(12);         
            frequence = Serial.parseInt();
            deltatime = Serial.parseInt();
            datalen = Serial.parseInt();
             status = 1;  
            }
     }
  }
  if (status == 1){ 
     int data = Serial.parseInt();
     Serial.print(data);   // echo
     Serial.print("|");
     if (data < 0){
        data = -data;
        }        
     rawData[index++] = data*deltatime; 
     if (index >= datalen){
         Serial.print("\n\r int read :");
         Serial.println(index);
         status = 2;
         }
     }
  if (status == 2){ 
     mySender.send(rawData,datalen,frequence);    //Pass the buffer,length, optionally frequency
     Serial.println("ir sended");  
     status = 0;
     } 
  if (status == 3){ 
  //Continue looping until you get the complete signal received
    if (myReceiver.getResults()) { 
           Serial.print(F("raw={"));
       for(bufIndex_t i=1;i<recvGlobal.recvLength;i++) {
         if( i % 2 == 1){
           Serial.print(recvGlobal.recvBuffer[i],DEC);
           Serial.print(F("|-"));
           }
          else{
           Serial.print(recvGlobal.recvBuffer[i],DEC);
           Serial.print(F("|"));
           }
        }
  //     Serial.print(recvGlobal.recvBuffer[recvGlobal.recvLength-4]*9,DEC); //Add arbitrary trailing space
       Serial.print(7000,DEC); //Add arbitrary trailing space
       Serial.println(F("}"));
  //    myReceiver.enableIRIn();      //Restart receiver
      status = 0;
       
      } else {
          delay(200);
    }
  }
}



  

