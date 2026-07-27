# DigitalPersona Java sample code for U.are.U SDK

LIB_DIR ?= ../../lib/java
DPJAR = dpuareu.jar

all:
	javac  -source 6 -target 6 -classpath $(LIB_DIR)/$(DPJAR) *.java
	jar -cvf UareUSampleJava.jar *.class

all_debug:all

clean:
	rm -f *.jar
	rm -f *.class
	rm -f *~

run:
	LD_LIBRARY_PATH=../../lib/x64 java -cp ".:$(LIB_DIR)/$(DPJAR)" UareUSampleJava
