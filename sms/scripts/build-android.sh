#!/bin/sh
set -eu
cd "$(dirname "$0")/../android"
: "${JAVA_HOME:?Set JAVA_HOME to JDK 17}"
: "${ANDROID_HOME:?Set ANDROID_HOME to an Android SDK with platform 36}"
./gradlew testDebugUnitTest lintDebug assembleDebug
