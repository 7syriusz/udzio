#!/bin/sh
# Run from any directory. All signing material stays in ignored runtime/.
set -eu
cd "$(dirname "$0")/.."
: "${JAVA_HOME:?Set JAVA_HOME to JDK 17}"
: "${ANDROID_HOME:?Set ANDROID_HOME to Android SDK}"
export PATH="$JAVA_HOME/bin:$PATH"
umask 077
mkdir -p runtime artifacts
if [ ! -f runtime/signing.env ]; then
    python3 - <<'PY'
from pathlib import Path
import secrets
p=Path('runtime/signing.env')
password=secrets.token_hex(32)
p.write_text('LAB_STORE_PASSWORD='+password+'\nLAB_KEY_PASSWORD='+password+'\nLAB_KEY_ALIAS=smslab\n')
p.chmod(0o600)
PY
fi
set -a
. ./runtime/signing.env
set +a
export LAB_KEYSTORE="$(pwd)/runtime/smslab.jks"
if [ ! -f "$LAB_KEYSTORE" ]; then
    "$JAVA_HOME/bin/keytool" -genkeypair -keystore "$LAB_KEYSTORE" -storepass:env LAB_STORE_PASSWORD -keypass:env LAB_KEY_PASSWORD -alias "$LAB_KEY_ALIAS" -keyalg RSA -keysize 3072 -validity 3650 -dname 'CN=UdzioSMS Lab,OU=Private prototype' >/dev/null
fi
(cd android && ./gradlew testDebugUnitTest lintDebug assembleRelease)
cp android/app/build/outputs/apk/release/app-release.apk artifacts/UdzioSMS-Lab.apk
(cd artifacts && sha256sum UdzioSMS-Lab.apk > SHA256SUMS)
"$ANDROID_HOME/build-tools/36.0.0/apksigner" verify --verbose artifacts/UdzioSMS-Lab.apk
printf '%s\n' 'APK: sms/artifacts/UdzioSMS-Lab.apk. Zachowaj bezpieczną kopię runtime/smslab.jks i runtime/signing.env dla kolejnych aktualizacji.'
