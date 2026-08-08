# One-click installer for cPanel

Run the installer from the project root:

Windows:
- install.bat

Or directly with PHP:
- php backend/install.php

The installer will:
- prompt for MySQL host, port, username, password, and database name
- create backend/.env for production
- initialize the database schema
- create the default admin account

Default admin login:
- username: admin
- password: admin123
