# ​🛠️ Requirements Installation ###
​These commands ensure your Termux environment is up-to-date and has the necessary tools.

```bash 
pkg update
pkg upgrade
```
## 📦 Core Tool Installation
These commands install the specific tools needed for your workflow (GitHub projects often use git and php).
```bash
pkg install git
pkg install php
```
### 🚀 Step-by-Step Setup
​Now lets clone the git repo on to our  Termux and handle r7p script or tool that launch local host website using php.
1. Cloning Git repo.
```bash
git clone https://github.com/AlienQ7/dev.git
```
2.Moving file to bin directory for easy access.
```bash
cd dev
```
```bash
mv r7p /data/data/com.termux/files/usr/bin
```
```bash
cd $PREFIX/bin
```
3. lets Give premission to r7p.
```bash
chmod +x r7p
```
4. Navgating back to dev directory to excute our main file which is 'index.php'
```bash
cd
```
```bash
cd dev
```
5. Launch the local website hoster
```bash
r7p
```
now enter the file name 'index.php' when r7p script asked for input.

6. Remember the website will be active as long as Termux app in open in background ,so to close the website run this command 
```bash
r7p kill
```
