import subprocess
import sys

cmd = [
    "/home/john/Android/Sdk/cmdline-tools/latest/bin/sdkmanager",
    "--sdk_root=/home/john/Android/Sdk",
    "ndk;26.3.11579264",
    "platform-tools",
    "platforms;android-34",
    "build-tools;34.0.0"
]

print("Starting sdkmanager to install NDK, platforms, and platform-tools...")
proc = subprocess.Popen(cmd, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

try:
    # Send continuous 'y' to accept all licenses
    stdout, stderr = proc.communicate(input="y\n" * 200, timeout=180)
    print(stdout)
    print("Errors (if any):")
    print(stderr)
    print("Process finished with exit code:", proc.returncode)
except subprocess.TimeoutExpired:
    proc.kill()
    stdout, stderr = proc.communicate()
    print("Process timed out. Output captured:")
    print(stdout)
    print(stderr)
