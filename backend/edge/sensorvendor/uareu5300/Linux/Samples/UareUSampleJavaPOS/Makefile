# DigitalPersona Java sample code for U.are.U SDK
JAR_NAME = dpjavapos_app.jar

LIB_DIR ?= ../../lib/java

CP ?="$(LIB_DIR)/dpjavapos.jar:$(LIB_DIR)/jpos113.jar:$(LIB_DIR)/xercesImpl-2.6.2.jar:$(LIB_DIR)/xmlParserAPIs.jar"

all:
	javac   -source 6 -target 6  -d . -cp $(CP) $(TOP_DIR)src/com/digitalpersona/javapos/sampleapp/biometrics/*.java 
	jar -cvf $(JAR_NAME) com/digitalpersona/javapos/sampleapp/biometrics/*.class

all_debug:all

clean:
	rm -f *.jar
	rm -f `find -name *.class`
	rm -f *~

run:
	LD_LIBRARY_PATH=../../lib/x64 java -cp "./src:.:./config:dpjavapos_app.jar:$(LIB_DIR)/xmlParserAPIs.jar:$(LIB_DIR)/xercesImpl-2.6.2.jar:$(LIB_DIR)/jpos113.jar:$(LIB_DIR)/dpuareu.jar:$(LIB_DIR)/dpjavapos.jar" com.digitalpersona.javapos.sampleapp.biometrics.SampleApp

