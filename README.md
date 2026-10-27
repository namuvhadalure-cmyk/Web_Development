# 🌾 Crop Market Management System

A web-based application developed using PHP and MySQL to simplify crop trading between farmers and market owners. The system helps farmers submit crop details, track approval status, view estimated costs, and manage payments through a centralized platform.

## 📌 Project Overview

The Crop Market Management System provides a digital platform for farmers and market owners to manage crop submissions, approvals, pricing, payments, and related activities efficiently.

## ✨ Features

### 👨‍🌾 Farmer Module
- Farmer registration and login
- Submit crop details for owner approval
- View crop approval status
- View cost per quintal and estimated crop cost
- Access payment receipts and payment history
- Communicate through the messaging system

### 🏪 Market Owner Module
- Owner authentication and dashboard
- Review and approve submitted crops
- Manage crop pricing and estimated costs
- Manage farmer payments
- Generate and view payment receipts
- Track payment history

## 🛠️ Technologies Used

- **Frontend:** HTML, CSS
- **Backend:** PHP
- **Database:** MySQL
- **Local Server:** XAMPP
- **Version Control:** Git and GitHub

## ⚙️ Installation and Setup

### Prerequisites
- XAMPP installed on your computer
- PHP and MySQL/MariaDB
- Web browser
- Git (optional, for version control)

### Step 1: Clone the Repository

Open CMD and navigate to your XAMPP `htdocs` directory:

```bash
cd /d D:\xampp\htdocs
git clone YOUR_REPOSITORY_URL crop_market2
```

Replace `YOUR_REPOSITORY_URL` with your GitHub repository URL. If you already have the project folder, skip cloning.

### Step 2: Start XAMPP

1. Open the XAMPP Control Panel.
2. Start **Apache**.
3. Start **MySQL**.

### Step 3: Configure the Database

1. Open `http://localhost/phpmyadmin/`.
2. Create the database required by the project.
3. Import your project's database SQL file if available.
4. Open `db.php` and configure the database connection settings according to your local setup.

> Note: The database name, tables, and required SQL schema must match the actual project configuration.

### Step 4: Run the Application

Open your browser and visit:

```text
http://localhost/crop_market2/
```

Register or log in using valid accounts supported by your application.

## 📂 Project Structure

```text
crop_market2/
├── index.php
├── login.php
├── register.php
├── logout.php
├── db.php
├── approve.php
├── auth_farmer.php
├── auth_owner.php
├── farmer_dashboard.php
├── owner_dashboard.php
├── payment_history.php
├── payment_receipt.php
├── receipt.php
├── style.css
├── .gitignore
└── README.md
```

## 🎯 Project Objectives

- Digitize crop submission and approval workflows.
- Improve transparency in crop pricing and estimated costs.
- Simplify payment tracking and receipt management.
- Improve communication between farmers and market owners.
- Reduce manual work in crop market management.

## 🔮 Future Enhancements

- Real-time notifications for crop approvals and payments
- Improved farmer-owner messaging
- Secure online payment integration
- Market price updates
- Mobile-friendly interface
- Detailed sales and payment reports

## 🔐 Security

- Keep database credentials and secrets private.
- Use secure password hashing and session management.
- Validate and sanitize user inputs.
- Enforce role-based access control.
- Never commit real user data or production credentials to GitHub.

## 👩‍💻 Author

Developed as a PHP and MySQL web development project.

## 📄 License

Add a license if you intend to distribute the project under specific reuse terms.
