#!/usr/bin/env python3
"""Offline Android build using the installed SDK and one pinned QR decoder."""
import hashlib, os, pathlib, secrets, subprocess, zipfile
ROOT = pathlib.Path(__file__).resolve().parent
SDK = pathlib.Path(os.environ.get('ANDROID_HOME', str(pathlib.Path.home() / 'Library/Android/sdk')))
JAVA = pathlib.Path(os.environ.get('JAVA_HOME', '/Library/Java/JavaVirtualMachines/temurin-17.jdk/Contents/Home'))
BT = SDK / 'build-tools/35.0.0'
ANDROID = SDK / 'platforms/android-35/android.jar'
JAR = ROOT / 'libs/core-3.5.3.jar'
assert hashlib.sha256(JAR.read_bytes()).hexdigest() == '8d8064c1636fdaef7189dd9055c7d59950a8940a12f2293956446ec3c109fd82', 'QR dependency checksum mismatch'
BUILD = ROOT / 'build'
for name in ['classes', 'dex', 'tests']: (BUILD / name).mkdir(parents=True, exist_ok=True)
env = dict(os.environ, JAVA_HOME=str(JAVA), PATH=str(JAVA/'bin')+os.pathsep+os.environ['PATH'])
def run(*args): subprocess.run([str(x) for x in args], check=True, env=env, cwd=ROOT)
sources = sorted((ROOT/'src/main/java').rglob('*.java'))
run(JAVA/'bin/javac', '-encoding','UTF-8','-source','8','-target','8','-classpath',str(ANDROID)+os.pathsep+str(JAR),'-d',BUILD/'classes',*sources)
run(JAVA/'bin/javac','-encoding','UTF-8','-d',BUILD/'tests',ROOT/'src/main/java/com/paykr/observer/Protocol.java',ROOT/'tests/ProtocolTest.java')
run(JAVA/'bin/java','-cp',BUILD/'tests','com.paykr.observer.ProtocolTest')
run(JAVA/'bin/jar','cf',BUILD/'classes.jar','-C',BUILD/'classes','.')
run(BT/'d8','--min-api','26','--lib',ANDROID,'--output',BUILD/'dex',BUILD/'classes.jar',JAR)
run(BT/'aapt','package','-f','-M',ROOT/'src/main/AndroidManifest.xml','-I',ANDROID,'-F',BUILD/'unsigned.apk')
with zipfile.ZipFile(BUILD/'unsigned.apk','a') as apk:
    for dex in sorted((BUILD/'dex').glob('*.dex')): apk.write(dex,dex.name)
run(BT/'zipalign','-f','4',BUILD/'unsigned.apk',BUILD/'aligned.apk')
private=ROOT/'.private';private.mkdir(mode=0o700,exist_ok=True)
password=private/'signing-password';keystore=private/'release.p12'
if not keystore.exists():
    if password.exists(): raise RuntimeError('Signing password exists without key; inspect before regenerating')
    password.write_text(secrets.token_urlsafe(40));password.chmod(0o600)
    run(JAVA/'bin/keytool','-genkeypair','-keystore',keystore,'-storetype','PKCS12','-storepass:file',password,'-alias','paykr','-keyalg','RSA','-keysize','3072','-validity','3650','-dname','CN=Paykr Observer, O=Paykr')
    keystore.chmod(0o600)
output=BUILD/'paykr-observer-2.0.0.apk'
run(BT/'apksigner','sign','--ks',keystore,'--ks-pass','file:'+str(password),'--out',output,BUILD/'aligned.apk')
run(BT/'apksigner','verify','--verbose','--print-certs',output)
digest=hashlib.sha256(output.read_bytes()).hexdigest()
(BUILD/'SHA256SUMS').write_text(digest+'  '+output.name+'\n')
print('Built:',output,'\nSHA256:',digest)
