/*
  BEFORE UPLOADING, edit the SETTINGS section below:
    SERVER_IP    = the LAN IP of the PC that runs XAMPP (see "ipconfig")
    SERVER_PATH  = the web folder of BioTrack on that PC
    API_KEY      = same text as DEVICE_API_KEY in config/device_config.php
*/

#include <Adafruit_Fingerprint.h>
#include <SoftwareSerial.h>
#include <Wire.h>
#include <LiquidCrystal_I2C.h>
#include <SPI.h>
#include <Ethernet.h>

const uint8_t PIN_SENSOR_TX = 2;
const uint8_t PIN_SENSOR_RX = 3;

// LCD address: 0x27 (change to 0x3F if the screen stays blank)
LiquidCrystal_I2C lcd(0x27, 16, 2);

byte      MAC_ADDR[]  = {0xDE, 0xAD, 0xBE, 0xEF, 0xFE, 0xED};

#define USE_DHCP false
IPAddress ARDUINO_IP (192, 168, 1, 201);
IPAddress GATEWAY_IP (192, 168, 1, 1);
IPAddress SUBNET_MASK(255, 255, 255, 0);
const uint16_t TCP_PORT = 5000;

IPAddress SERVER_IP(192, 168, 1, 20);     // <-- CHANGE: IP of the XAMPP PC
const uint16_t SERVER_PORT = 80;
#define SERVER_PATH "/BioTrack_Device/device_punch.php"   // <-- CHANGE if your folder name differs
#define API_KEY     "change-me-1234"       // <-- CHANGE: must equal DEVICE_API_KEY in device_config.php

const uint32_t HOST_GRACE_MS  = 15000;
const uint32_t HTTP_TIMEOUT_MS = 5000;

const uint32_t WAIT_FINGER_MS = 20000;
const uint32_t WAIT_REMOVE_MS = 10000;
const uint32_t WAIT_SCAN_MS   = 30000;
const uint16_t MAX_SLOT       = 300;

const uint32_t BAUDS[] = {57600, 9600, 19200, 38400, 115200};
const uint8_t  BAUD_COUNT = sizeof(BAUDS) / sizeof(BAUDS[0]);

SoftwareSerial sensorSerial(PIN_SENSOR_TX, PIN_SENSOR_RX);
Adafruit_Fingerprint finger(&sensorSerial);

bool     sensorReady = false;
uint32_t foundBaud   = 0;
uint32_t lastHostMs  = 0;
bool     hostSeen    = false;

char    cmdBuf[56];
uint8_t cmdLen = 0;

enum { W_OK, W_TIMEOUT, W_CANCEL, W_SENSOR };

uint8_t CH_BLOCK[8] = {31, 31, 31, 31, 31, 31, 31, 31};
uint8_t CH_CHECK[8] = {0, 1, 3, 22, 28, 8, 0, 0};
uint8_t CH_CROSS[8] = {0, 27, 14, 4, 14, 27, 0, 0};
uint8_t CH_PRINT[8] = {14, 17, 21, 21, 21, 21, 17, 14};
#define C_BLOCK 0
#define C_CHECK 1
#define C_CROSS 2
#define C_PRINT 3

EthernetServer netServer(TCP_PORT);
EthernetClient netClient;
bool    netReady = false;
bool    clientUp = false;
char    netBuf[40];
uint8_t netLen = 0;

void netFlush() {
  if (netLen && clientUp) netClient.write((const uint8_t*)netBuf, netLen);
  netLen = 0;
}

void netPoll() {
  if (!netReady) return;
  if (!netClient.connected()) {
    if (clientUp) { netClient.stop(); clientUp = false; netLen = 0; }
    EthernetClient c = netServer.accept();
    if (c) { netClient = c; clientUp = true; netLen = 0; }
  } else {
    clientUp = true;
  }
}

class CommStream : public Stream {
 public:
  int available() override {
    netPoll();
    int n = Serial.available();
    if (netReady && clientUp) n += netClient.available();
    return n;
  }
  int read() override {
    if (Serial.available()) return Serial.read();
    if (netReady && clientUp) return netClient.read();
    return -1;
  }
  int peek() override {
    if (Serial.available()) return Serial.peek();
    if (netReady && clientUp) return netClient.peek();
    return -1;
  }
  void flush() override { Serial.flush(); netFlush(); }
  size_t write(uint8_t b) override {
    Serial.write(b);
    if (netReady && clientUp) {
      netBuf[netLen++] = (char)b;
      if (b == '\n' || netLen >= sizeof(netBuf)) netFlush();
    }
    return 1;
  }
};
CommStream Comm;

void lcdRow(uint8_t row, const char* text, bool center = false) {
  char line[17];
  uint8_t len = strlen(text);
  if (len > 16) len = 16;
  memset(line, ' ', 16);
  uint8_t start = center ? (16 - len) / 2 : 0;
  memcpy(line + start, text, len);
  line[16] = 0;
  lcd.setCursor(0, row);
  lcd.print(line);
}

void lcdRowF(uint8_t row, const __FlashStringHelper* text, bool center = false) {
  char buf[17];
  strncpy_P(buf, (PGM_P)text, 16);
  buf[16] = 0;
  lcdRow(row, buf, center);
}

void lcdBar(uint8_t blocks) {
  lcd.setCursor(0, 1);
  for (uint8_t i = 0; i < 16; i++) {
    if (i < blocks) lcd.write((uint8_t)C_BLOCK); else lcd.print(' ');
  }
}

void lcdEnrollStep(uint16_t slot, uint8_t step, const __FlashStringHelper* msg) {
  char top[17];
  snprintf(top, sizeof(top), "Enroll #%-3u  %u/4", (unsigned)slot, step);
  lcdRow(0, top);
  lcdRowF(1, msg);
}

void lcdTitle(const __FlashStringHelper* line1, const __FlashStringHelper* line2) {
  lcdRowF(0, line1, true);
  lcdRowF(1, line2, true);
}

void showIdle() {
  lcdRowF(0, F("   BioTrack"), false);
  lcd.setCursor(2, 0);
  lcd.write((uint8_t)C_PRINT);
  lcdRowF(1, F("Place finger"), true);
}

void lcdResult(bool ok, const __FlashStringHelper* line1, const char* line2) {
  lcdRowF(0, line1, true);
  lcdRow(1, line2, true);

  lcd.setCursor(0, 0);
  lcd.write((uint8_t)(ok ? C_CHECK : C_CROSS));
  lcd.setCursor(15, 0);
  lcd.write((uint8_t)(ok ? C_CHECK : C_CROSS));
}

void splash() {
  lcd.clear();
  lcdRowF(0, F("   BioTrack"), false);
  lcd.setCursor(2, 0);
  lcd.write((uint8_t)C_PRINT);
  lcdRowF(1, F("Fingerprint Sys"), true);
  delay(400);
  lcdRowF(0, F("Starting..."), true);
  for (uint8_t i = 1; i <= 16; i++) { lcdBar(i); delay(12); }
}

void showMemory() {
  if (finger.getTemplateCount() != FINGERPRINT_OK) {
    Comm.println(F("# Could not read how many fingerprints are stored."));
    return;
  }
  unsigned int used  = finger.templateCount;
  unsigned int total = finger.capacity;
  unsigned int freeSlots = (used >= total) ? 0 : (total - used);

  Comm.print(F("OK USED "));
  Comm.print(used);
  Comm.print(' ');
  Comm.println(total);

  Comm.println(F("# ---------- Scanner memory ----------"));
  Comm.print(F("#   Used : ")); Comm.print(used); Comm.print(F(" of ")); Comm.println(total);
  Comm.print(F("#   Free : ")); Comm.println(freeSlots);
  if (freeSlots == 0) Comm.println(F("#   !! Memory is FULL."));
  Comm.println(F("# ------------------------------------"));
}

void showHelp() {
  Comm.println(F("# Commands:"));
  Comm.println(F("#   PING  COUNT  ENROLL <slot>  DELETE <slot>  SCAN  CANCEL  DIAG  HELP"));
}

bool detectSensor() {
  for (uint8_t i = 0; i < BAUD_COUNT; i++) {
    finger.begin(BAUDS[i]);
    delay(100);
    if (finger.verifyPassword()) {
      foundBaud = BAUDS[i];
      finger.getParameters();
      if (finger.capacity == 0) finger.capacity = MAX_SLOT;
      return true;
    }
  }
  return false;
}

void reportSensor() {
  if (sensorReady) {
    Comm.print(F("OK READY "));
    Comm.println((unsigned int)finger.capacity);
    Comm.print(F("# Sensor found (speed "));
    Comm.print(foundBaud);
    Comm.println(F(")."));

    char l2[17];
    snprintf(l2, sizeof(l2), "Capacity: %u", (unsigned)finger.capacity);
    lcdResult(true, F("Sensor Found"), l2);
    showMemory();
    delay(400);
    showIdle();
  } else {
    Comm.println(F("ERR NOSENSOR"));
    Comm.println(F("# Sensor NOT found. Check wiring."));
    lcdResult(false, F("Sensor Error"), "Check wiring");
  }
}

bool bufIsCancel() {
  return (cmdLen >= 6 && strncasecmp(cmdBuf, "CANCEL", 6) == 0) ||
         (cmdLen >= 4 && strncasecmp(cmdBuf, "STOP", 4) == 0);
}

bool cancelRequested() {
  while (Comm.available()) {
    char c = Comm.read();
    if (c == '\n' || c == '\r') {
      bool isCancel = bufIsCancel();
      cmdLen = 0;
      if (isCancel) return true;
    } else {
      if (c == ' ' && cmdLen == 0) continue;
      if (cmdLen < sizeof(cmdBuf) - 1) cmdBuf[cmdLen++] = c;

      if (bufIsCancel()) { cmdLen = 0; return true; }
    }
  }
  return false;
}

uint8_t waitForFinger(uint32_t timeoutMs) {
  uint32_t t0 = millis();
  uint8_t commErrors = 0;

  while (millis() - t0 < timeoutMs) {
    if (cancelRequested()) { return W_CANCEL; }

    uint8_t p = finger.getImage();
    if (p == FINGERPRINT_OK) {
      delay(300);
      if (finger.getImage() == FINGERPRINT_OK) {
        return W_OK;
      }
      continue;
    }

    if (p == FINGERPRINT_PACKETRECIEVEERR || p == FINGERPRINT_TIMEOUT || p == FINGERPRINT_BADPACKET) {
      if (++commErrors >= 10) { return W_SENSOR; }
    } else {
      commErrors = 0;
    }
    delay(40);
  }
  return W_TIMEOUT;
}

uint8_t waitForRemoval(uint32_t timeoutMs) {
  uint32_t t0 = millis();
  uint8_t clearReads = 0;
  while (millis() - t0 < timeoutMs) {
    if (cancelRequested()) return W_CANCEL;
    if (finger.getImage() == FINGERPRINT_NOFINGER) {
      if (++clearReads >= 3) return W_OK;
    } else {
      clearReads = 0;
    }
    delay(40);
  }
  return W_TIMEOUT;
}

void failWait(uint8_t w) {
  if (w == W_TIMEOUT) {
    Comm.println(F("ERR TIMEOUT"));
    lcdResult(false, F("No Finger"), "Try again");
  } else if (w == W_CANCEL) {
    Comm.println(F("ERR CANCELLED"));
    lcdTitle(F("Scan Stopped"), F(""));
  } else {
    Comm.println(F("ERR SENSOR"));
    lcdResult(false, F("Sensor Error"), "Check wiring");
  }
  delay(w == W_CANCEL ? 600 : 1200);
  showIdle();
}

void enrollFail(const __FlashStringHelper* serialMsg, const __FlashStringHelper* l1, const char* l2) {
  Comm.println(serialMsg);
  lcdResult(false, l1, l2);
  delay(1500);
  showIdle();
}

void enrollFinger(uint16_t slot) {
  if (!sensorReady) { Comm.println(F("ERR NOSENSOR")); return; }
  if (slot < 1 || slot > MAX_SLOT) { Comm.println(F("ERR BADID")); return; }

  uint8_t w, p;

  Comm.print(F("# Adding a fingerprint into slot "));
  Comm.println(slot);

  Comm.println(F("STATUS PLACE"));
  lcdEnrollStep(slot, 1, F("Place finger"));
  w = waitForFinger(WAIT_FINGER_MS);
  if (w != W_OK) { failWait(w); return; }

  p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) { enrollFail(F("ERR BADIMAGE"), F("Bad Scan"), "Clean sensor"); return; }
  Comm.println(F("STATUS CAPTURED1"));

  uint8_t searchResult = finger.fingerFastSearch();
  if (searchResult == FINGERPRINT_OK && finger.fingerID != slot) {
    Comm.print(F("ERR DUPLICATE "));
    Comm.println((unsigned int)finger.fingerID);
    char l2[17];
    snprintf(l2, sizeof(l2), "Already ID #%u", (unsigned)finger.fingerID);
    lcdResult(false, F("Duplicate"), l2);
    delay(1800);
    showIdle();
    return;
  }

  Comm.println(F("STATUS REMOVE"));
  lcdEnrollStep(slot, 2, F("Lift finger"));
  w = waitForRemoval(WAIT_REMOVE_MS);
  if (w != W_OK) { failWait(w); return; }

  Comm.println(F("STATUS PLACE_AGAIN"));
  lcdEnrollStep(slot, 3, F("Place same again"));
  w = waitForFinger(WAIT_FINGER_MS);
  if (w != W_OK) { failWait(w); return; }

  p = finger.image2Tz(2);
  if (p != FINGERPRINT_OK) { enrollFail(F("ERR BADIMAGE"), F("Bad Scan"), "Clean sensor"); return; }
  Comm.println(F("STATUS CAPTURED2"));

  Comm.println(F("STATUS PROCESSING"));
  lcdEnrollStep(slot, 4, F("Saving..."));

  p = finger.createModel();
  if (p == FINGERPRINT_ENROLLMISMATCH) {
    enrollFail(F("ERR MISMATCH"), F("No Match"), "Use same finger");
    return;
  }
  if (p != FINGERPRINT_OK) { enrollFail(F("ERR SENSOR"), F("Model Error"), "Try again"); return; }

  p = finger.storeModel(slot);
  if (p != FINGERPRINT_OK) { enrollFail(F("ERR STORE"), F("Save Error"), "Try again"); return; }

  Comm.print(F("OK ENROLLED "));
  Comm.println((unsigned int)slot);

  char l2[17];
  snprintf(l2, sizeof(l2), "Saved as ID #%u", (unsigned)slot);
  lcdResult(true, F("Success!"), l2);
  showMemory();
  delay(1200);
  showIdle();
}

void deleteFinger(uint16_t slot) {
  if (!sensorReady || slot < 1 || slot > MAX_SLOT) {
    Comm.println(F("ERR DELETE"));
    return;
  }

  Comm.print(F("# Removing fingerprint in slot "));
  Comm.println(slot);

  char l2[17];
  snprintf(l2, sizeof(l2), "Slot #%u", (unsigned)slot);
  lcdRowF(0, F("Deleting..."), true);
  lcdRow(1, l2, true);

  bool gone = (finger.deleteModel(slot) == FINGERPRINT_OK);

  if (!gone) {
    uint8_t p = finger.loadModel(slot);
    bool sensorSilent = (p == FINGERPRINT_PACKETRECIEVEERR ||
                         p == FINGERPRINT_TIMEOUT ||
                         p == FINGERPRINT_BADPACKET);
    if (p != FINGERPRINT_OK && !sensorSilent) gone = true;
  }

  if (!gone) {
    Comm.println(F("ERR DELETE"));
    lcdResult(false, F("Delete Failed"), l2);
    delay(1500);
    showIdle();
    return;
  }

  Comm.print(F("OK DELETED "));
  Comm.println(slot);

  lcdResult(true, F("Deleted"), l2);
  showMemory();
  delay(1200);
  showIdle();
}

const uint32_t WAIT_SHOW_MS = 3000;

bool waitForShow(uint32_t timeoutMs, bool &isIn, char* nameOut, uint8_t nameMax) {
  uint32_t t0 = millis();
  cmdLen = 0;
  while (millis() - t0 < timeoutMs) {
    while (Comm.available()) {
      char c = Comm.read();
      if (c == '\n' || c == '\r') {
        if (cmdLen == 0) continue;
        cmdBuf[cmdLen] = 0;
        cmdLen = 0;

        const char* rest = NULL;
        if      (strncmp(cmdBuf, "SHOW IN ",  8) == 0) { isIn = true;  rest = cmdBuf + 8; }
        else if (strncmp(cmdBuf, "SHOW OUT ", 9) == 0) { isIn = false; rest = cmdBuf + 9; }

        if (rest) {
          uint8_t n = 0;
          for (; *rest && n < nameMax - 1; rest++) {
            uint8_t ch = (uint8_t)*rest;
            if (ch >= 32 && ch < 127) nameOut[n++] = ch;
          }
          nameOut[n] = 0;
          return n > 0;
        }
      } else if (cmdLen < sizeof(cmdBuf) - 1) {
        cmdBuf[cmdLen++] = c;
      }
    }
  }
  return false;
}

void showPerson(const char* name, bool isIn) {
  lcd.clear();
  lcdRowF(1, isIn ? F("TIME IN") : F("TIME OUT"), true);
  lcd.setCursor(0, 1);
  lcd.write((uint8_t)C_CHECK);
  lcd.setCursor(15, 1);
  lcd.write((uint8_t)C_CHECK);

  uint8_t len = strlen(name);
  if (len <= 16) {
    lcdRow(0, name, true);
    delay(2500);
  } else {
    lcdRow(0, name);
    delay(900);
    for (uint8_t off = 1; off <= len - 16; off++) {
      lcdRow(0, name + off);
      delay(350);
    }
    delay(900);
  }
}

void scanFinger() {
  if (!sensorReady) { Comm.println(F("ERR NOSENSOR")); return; }

  lcdTitle(F("Attendance"), F("Place finger"));

  uint8_t w = waitForFinger(WAIT_SCAN_MS);
  if (w != W_OK) { failWait(w); return; }

  uint8_t p = finger.image2Tz(1);
  if (p != FINGERPRINT_OK) {
    Comm.println(F("ERR BADIMAGE"));
    lcdResult(false, F("Bad Scan"), "Try again");
  } else {
    p = finger.fingerFastSearch();
    if (p == FINGERPRINT_OK) {
      Comm.print(F("OK MATCH "));
      Comm.print((unsigned int)finger.fingerID);
      Comm.print(' ');
      Comm.println((unsigned int)finger.confidence);

      lcdTitle(F("Checking..."), F("Please wait"));

      bool isIn = true;
      char name[40];
      if (waitForShow(WAIT_SHOW_MS, isIn, name, sizeof(name))) {
        showPerson(name, isIn);
      } else {

        char l2[17];
        snprintf(l2, sizeof(l2), "ID #%u", (unsigned)finger.fingerID);
        lcdResult(true, F("Scan OK"), l2);
        delay(1500);
      }
    } else if (p == FINGERPRINT_NOTFOUND) {
      Comm.println(F("ERR NOMATCH"));
      lcdResult(false, F("Unknown Finger"), "Try again");
      delay(1200);
    } else {
      Comm.println(F("ERR SENSOR"));
      lcdResult(false, F("Sensor Error"), "Check wiring");
    }
  }

  waitForRemoval(WAIT_REMOVE_MS);
  Comm.println(F("STATUS LIFTED"));
  showIdle();
}

uint8_t lastHttpFail = 0;

bool httpPunch(uint16_t slot, char* reply, uint8_t replyMax) {
  lastHttpFail = 1;
  if (!netReady) return false;

  EthernetClient http;
  if (!http.connect(SERVER_IP, SERVER_PORT)) return false;
  lastHttpFail = 2;

  uint8_t digits = (slot >= 100) ? 3 : (slot >= 10 ? 2 : 1);
  uint8_t clen = 4 + (sizeof(API_KEY) - 1) + 6 + digits;

  http.print(F("POST ")); http.print(F(SERVER_PATH)); http.println(F(" HTTP/1.0"));
  http.print(F("Host: ")); http.println(SERVER_IP);
  http.println(F("Content-Type: application/x-www-form-urlencoded"));
  http.print(F("Content-Length: ")); http.println(clen);
  http.println(F("Connection: close"));
  http.println();
  http.print(F("key=")); http.print(F(API_KEY));
  http.print(F("&slot=")); http.print(slot);

  uint8_t hdr = 0, n = 0;
  uint32_t t0 = millis();
  while ((http.connected() || http.available()) && millis() - t0 < HTTP_TIMEOUT_MS) {
    if (!http.available()) continue;
    char c = http.read();
    if (hdr < 4) {
      char want = (hdr % 2 == 0) ? '\r' : '\n';
      if (c == want) hdr++; else hdr = (c == '\r') ? 1 : 0;
    } else {
      if (c == '\n' || c == '\r') { if (n > 0) break; else continue; }
      if (n < replyMax - 1) reply[n++] = c;
    }
  }
  reply[n] = 0;
  http.stop();
  if (n > 0) lastHttpFail = 0;
  return n > 0;
}

void showPunchReply(char* reply) {
  char* name = strchr(reply, '|');
  if (!name) { lcdResult(false, F("Server Error"), "Bad answer"); delay(1500); return; }
  *name++ = 0;

  if (strcmp(reply, "ERR") == 0) {
    if      (strcmp(name, "NOLINK") == 0) lcdResult(false, F("Not Linked"), "Ask admin");
    else if (strcmp(name, "KEY")    == 0) lcdResult(false, F("Bad API Key"), "Check sketch");
    else                                   lcdResult(false, F("Server Error"), "Try again");
    delay(2000);
    return;
  }

  char* tm = strchr(name, '|');
  if (!tm) { lcdResult(false, F("Server Error"), "Bad answer"); delay(1500); return; }
  *tm++ = 0;
  char* st = strchr(tm, '|');
  if (st) *st++ = 0; else st = (char*)"";

  if (strcmp(reply, "IN") == 0 || strcmp(reply, "OUT") == 0) {
    bool isIn = (reply[0] == 'I');
    showPerson(name, isIn);
    lcd.clear();
    lcdRow(0, tm, true);
    if (isIn) lcdRowF(1, (strcmp(st, "Late") == 0) ? F("LATE") : F("On time"), true);
    else      lcdRowF(1, F("Goodbye!"), true);
    delay(1500);
  }
  else if (strcmp(reply, "DUP") == 0) {
    lcdRow(0, name, true);
    lcdRowF(1, F("Already logged"), true);
    delay(1800);
  }
  else if (strcmp(reply, "NONE") == 0) {
    lcdRow(0, name, true);
    lcdRowF(1, F("Nothing new"), true);
    delay(1500);
  }
  else {
    lcdResult(false, F("Server Error"), "Try again");
    delay(2000);
  }
}

bool hostBusy() {
  return hostSeen && (millis() - lastHostMs < HOST_GRACE_MS);
}

void autoScan() {
  if (!sensorReady) return;

  if (finger.getImage() != FINGERPRINT_OK) return;
  delay(300);
  if (finger.getImage() != FINGERPRINT_OK) return;

  if (finger.image2Tz(1) != FINGERPRINT_OK) {
    lcdResult(false, F("Bad Scan"), "Try again");
    delay(1200);
  } else {
    uint8_t p = finger.fingerFastSearch();
    if (p == FINGERPRINT_OK) {
      uint16_t slot = finger.fingerID;
      lcdTitle(F("Checking..."), F("Please wait"));

      char reply[48];
      if (httpPunch(slot, reply, sizeof(reply))) {
        showPunchReply(reply);
      } else {
        if (lastHttpFail == 1) lcdResult(false, F("PC Not Found"), "Check SERVER_IP");
        else                   lcdResult(false, F("Server Offline"), "Not recorded");
        delay(2000);
      }
    } else if (p == FINGERPRINT_NOTFOUND) {
      lcdResult(false, F("Unknown Finger"), "Try again");
      delay(1200);
    } else {
      lcdResult(false, F("Sensor Error"), "Check wiring");
      delay(1200);
    }
  }

  lcdTitle(F("Lift finger"), F(""));
  waitForRemoval(WAIT_REMOVE_MS);
  showIdle();
}

void runDiag() {
  const uint8_t hello[] = {0xEF, 0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0x01, 0x00, 0x07,
                           0x13, 0x00, 0x00, 0x00, 0x00, 0x00, 0x1B};
  lcdTitle(F("Diagnostics"), F("Testing speeds"));
  for (uint8_t i = 0; i < BAUD_COUNT; i++) {
    sensorSerial.begin(BAUDS[i]);
    delay(100);
    while (sensorSerial.available()) sensorSerial.read();
    sensorSerial.write(hello, sizeof(hello));

    Comm.print(F("DIAG "));
    Comm.print(BAUDS[i]);
    Comm.print(':');
    uint8_t n = 0;
    uint32_t t0 = millis();
    while (millis() - t0 < 400) {
      while (sensorSerial.available() && n < 24) {
        uint8_t b = sensorSerial.read();
        Comm.print(' ');
        if (b < 16) Comm.print('0');
        Comm.print(b, HEX);
        n++;
      }
    }
    if (n == 0) Comm.print(F(" no reply"));
    Comm.println();
  }
  Comm.println(F("DIAG END"));
  sensorReady = false;
  showIdle();
}

void handleCommand(const char* cmd) {
  if (strcmp(cmd, "PING") == 0) {
    if (!sensorReady) sensorReady = detectSensor();
    reportSensor();
  }
  else if (strcmp(cmd, "COUNT") == 0) {
    if (sensorReady) showMemory(); else Comm.println(F("ERR NOSENSOR"));
  }
  else if (strncmp(cmd, "ENROLL ", 7) == 0) enrollFinger(atoi(cmd + 7));
  else if (strncmp(cmd, "DELETE ", 7) == 0) deleteFinger(atoi(cmd + 7));
  else if (strcmp(cmd, "SCAN") == 0)         scanFinger();
  else if (strcmp(cmd, "DIAG") == 0)         runDiag();
  else if (strcmp(cmd, "HELP") == 0)         showHelp();
}

void startNetwork() {
  pinMode(4, OUTPUT);
  digitalWrite(4, HIGH);

  lcdTitle(F("Network"), F("Connecting..."));

#if USE_DHCP

  if (Ethernet.begin(MAC_ADDR, 4000, 1500) == 0) {
    Ethernet.begin(MAC_ADDR, ARDUINO_IP, GATEWAY_IP, GATEWAY_IP, SUBNET_MASK);
  }
#else

  Ethernet.begin(MAC_ADDR, ARDUINO_IP, GATEWAY_IP, GATEWAY_IP, SUBNET_MASK);
#endif

  if (Ethernet.hardwareStatus() == EthernetNoHardware) {
    netReady = false;
    Comm.println(F("# Ethernet shield NOT found (USB serial still works)."));
    lcdResult(false, F("No Ethernet"), "Check shield");
    delay(1500);
    return;
  }

  netServer.begin();
  netReady = true;

  IPAddress ip = Ethernet.localIP();
  char l2[17];
  snprintf(l2, sizeof(l2), "%u.%u.%u.%u", ip[0], ip[1], ip[2], ip[3]);
  Comm.print(F("# Network ready: "));
  Comm.print(l2);
  Comm.print(F(" port "));
  Comm.println(TCP_PORT);
  lcdRowF(0, F("Network OK"), true);
  lcdRow(1, l2, true);
  delay(1200);

  lcdTitle(F("Checking PC..."), F("Please wait"));
  EthernetClient t;
  if (t.connect(SERVER_IP, SERVER_PORT)) {
    t.stop();
    lcdRowF(0, F("PC Connected"), true);
    Comm.println(F("# BioTrack PC reachable."));
  } else {
    lcdRowF(0, F("PC NOT Found"), true);
    Comm.println(F("# Cannot reach SERVER_IP - check the IP, Apache and the Windows Firewall."));
  }
  snprintf(l2, sizeof(l2), "%u.%u.%u.%u", SERVER_IP[0], SERVER_IP[1], SERVER_IP[2], SERVER_IP[3]);
  lcdRow(1, l2, true);
  delay(2500);
}

void setup() {
  Serial.begin(9600);

  lcd.init();
  lcd.backlight();
  lcd.createChar(C_BLOCK, CH_BLOCK);
  lcd.createChar(C_CHECK, CH_CHECK);
  lcd.createChar(C_CROSS, CH_CROSS);
  lcd.createChar(C_PRINT, CH_PRINT);

  splash();

  sensorReady = detectSensor();
  reportSensor();

  startNetwork();
  showIdle();
}

void loop() {
  Ethernet.maintain();
  while (Comm.available()) {
    char c = Comm.read();
    if (c == '\n' || c == '\r') {
      if (cmdLen > 0) {
        cmdBuf[cmdLen] = 0;
        cmdLen = 0;
        hostSeen = true;
        handleCommand(cmdBuf);
        lastHostMs = millis();
      }
    } else if (cmdLen < sizeof(cmdBuf) - 1) {
      cmdBuf[cmdLen++] = c;
    }
  }

  if (!hostBusy()) autoScan();
  else delay(20);
}
