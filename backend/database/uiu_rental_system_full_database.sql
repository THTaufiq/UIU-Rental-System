-- ============================================================
-- UIU STUDENT RENTAL SYSTEM
-- Full MySQL Database for the supplied frontend
-- Target: MySQL 8.0+
-- ============================================================

DROP DATABASE IF EXISTS uiu_rental_system;
CREATE DATABASE uiu_rental_system
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE uiu_rental_system;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. CORE USERS / AUTHENTICATION
-- ============================================================

CREATE TABLE roles (
    role_id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(30) NOT NULL UNIQUE,
    description VARCHAR(255)
) ENGINE=InnoDB;

INSERT INTO roles (role_name, description) VALUES
('student', 'UIU student/tenant'),
('landlord', 'Property owner/landlord'),
('admin', 'Platform administrator');

CREATE TABLE users (
    user_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id TINYINT UNSIGNED NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    avatar_url VARCHAR(500),
    bio TEXT,
    institution VARCHAR(150) NULL,
    emergency_contact VARCHAR(50) NULL,
    nid_number VARCHAR(50) NULL,
    bkash_number VARCHAR(40) NULL,
    nagad_number VARCHAR(40) NULL,
    bank_name VARCHAR(120) NULL,
    bank_account_no VARCHAR(80) NULL,
    bank_routing_no VARCHAR(50) NULL,
    account_status ENUM('pending','active','suspended','rejected','deleted')
        NOT NULL DEFAULT 'pending',
    email_verified BOOLEAN NOT NULL DEFAULT FALSE,
    phone_verified BOOLEAN NOT NULL DEFAULT FALSE,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(role_id),
    INDEX idx_users_role_status (role_id, account_status),
    INDEX idx_users_name (full_name),
    INDEX idx_users_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE student_profiles (
    student_id INT UNSIGNED PRIMARY KEY,
    university_student_id VARCHAR(30) NOT NULL UNIQUE,
    program VARCHAR(100) NOT NULL,
    permanent_address VARCHAR(500),
    verification_document VARCHAR(500),
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE landlord_profiles (
    landlord_id INT UNSIGNED PRIMARY KEY,
    nid_number VARCHAR(50) NOT NULL UNIQUE,
    trade_license_number VARCHAR(80),
    permanent_address VARCHAR(500),
    verification_document VARCHAR(500),
    verification_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    member_since DATE NULL,
    payout_method ENUM('bkash','nagad','rocket','bank_transfer') NULL,
    bkash_account VARCHAR(40),
    nagad_account VARCHAR(40),
    rocket_account VARCHAR(40),
    bank_name VARCHAR(120),
    bank_account_number VARCHAR(80),
    FOREIGN KEY (landlord_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_settings (
    user_id INT UNSIGNED PRIMARY KEY,
    language ENUM('English','বাংলা') NOT NULL DEFAULT 'English',
    currency ENUM('BDT','USD') NOT NULL DEFAULT 'BDT',
    email_notifications BOOLEAN NOT NULL DEFAULT TRUE,
    application_notifications BOOLEAN NOT NULL DEFAULT TRUE,
    payment_notifications BOOLEAN NOT NULL DEFAULT TRUE,
    maintenance_notifications BOOLEAN NOT NULL DEFAULT TRUE,
    chat_notifications BOOLEAN NOT NULL DEFAULT TRUE,
    dark_mode BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 2. PROPERTY CATEGORIES / FACILITIES
-- ============================================================

CREATE TABLE property_categories (
    category_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO property_categories (category_name, description) VALUES
('Studio Apartment','Private studio apartment'),
('Single Room','Private single room'),
('2 Bedroom Flat','Two-bedroom flat'),
('3 Bedroom Flat','Three-bedroom flat'),
('Mess / Shared Room','Shared student accommodation'),
('Sublet','Short-term/sublet accommodation');

CREATE TABLE facilities (
    facility_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_name VARCHAR(80) NOT NULL UNIQUE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

INSERT INTO facilities (facility_name) VALUES
('Wi-Fi'),('AC'),('Attached Bath'),('Shared Bath'),('Kitchen'),
('Dining'),('Generator'),('Lift'),('Parking'),('Security'),
('CCTV'),('Gas'),('Water Supply'),('Electricity Backup'),
('Furnished'),('Balcony'),('Washing Machine'),('Study Table');

-- ============================================================
-- 3. PROPERTIES
-- ============================================================

CREATE TABLE properties (
    property_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    landlord_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    neighborhood VARCHAR(100) NOT NULL,
    full_address VARCHAR(500) NOT NULL,
    city VARCHAR(80) NOT NULL DEFAULT 'Dhaka',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    distance_from_uiu_km DECIMAL(5,2) NOT NULL,
    floor_label VARCHAR(40),
    bedroom_count DECIMAL(4,1) NOT NULL DEFAULT 1,
    bathroom_count DECIMAL(4,1) NOT NULL DEFAULT 1,
    area_sqft DECIMAL(8,2) NULL,
    monthly_rent DECIMAL(12,2) NOT NULL,
    security_deposit DECIMAL(12,2) NOT NULL,
    service_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    minimum_stay_months INT UNSIGNED NOT NULL DEFAULT 1,
    available_from DATE NULL,
    house_rules TEXT,
    status ENUM('pending','approved','rejected','removed','rented','unavailable')
        NOT NULL DEFAULT 'pending',
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    featured BOOLEAN NOT NULL DEFAULT FALSE,
    total_views INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (landlord_id) REFERENCES users(user_id),
    FOREIGN KEY (category_id) REFERENCES property_categories(category_id),
    INDEX idx_property_search (status, category_id, monthly_rent, distance_from_uiu_km),
    INDEX idx_property_landlord (landlord_id, status),
    INDEX idx_property_location (neighborhood),
    INDEX idx_property_available (available_from)
) ENGINE=InnoDB;

CREATE TABLE property_images (
    image_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    image_url VARCHAR(700) NOT NULL,
    is_primary BOOLEAN NOT NULL DEFAULT FALSE,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    INDEX idx_images_property (property_id, sort_order)
) ENGINE=InnoDB;

CREATE TABLE property_facilities (
    property_id INT UNSIGNED NOT NULL,
    facility_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (property_id, facility_id),
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (facility_id) REFERENCES facilities(facility_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE property_views (
    view_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    viewer_user_id INT UNSIGNED NULL,
    viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_hash VARCHAR(128) NULL,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (viewer_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_views_property_date (property_id, viewed_at)
) ENGINE=InnoDB;

-- ============================================================
-- 4. FAVORITES / SAVED PROPERTIES
-- ============================================================

CREATE TABLE favorites (
    favorite_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    property_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_favorite (student_id, property_id),
    FOREIGN KEY (student_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. RENTAL APPLICATIONS
-- ============================================================

CREATE TABLE rental_applications (
    application_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    move_in_date DATE NOT NULL,
    duration_months INT UNSIGNED NOT NULL,
    message TEXT,
    status ENUM('pending','approved','rejected','withdrawn','cancelled')
        NOT NULL DEFAULT 'pending',
    rejection_reason VARCHAR(255) NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at DATETIME NULL,
    reviewed_by INT UNSIGNED NULL,
    landlord_note TEXT,
    UNIQUE KEY uq_active_application (property_id, student_id, status),
    FOREIGN KEY (property_id) REFERENCES properties(property_id),
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_application_student (student_id, status),
    INDEX idx_application_property (property_id, status),
    INDEX idx_application_date (applied_at)
) ENGINE=InnoDB;

-- ============================================================
-- 6. RENTAL AGREEMENTS / CURRENT RENTALS
-- ============================================================

CREATE TABLE rental_agreements (
    agreement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    application_id BIGINT UNSIGNED NULL,
    property_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    landlord_id INT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    duration_months INT UNSIGNED NOT NULL,
    monthly_rent DECIMAL(12,2) NOT NULL,
    security_deposit DECIMAL(12,2) NOT NULL DEFAULT 0,
    service_charge DECIMAL(12,2) NOT NULL DEFAULT 0,
    terms TEXT,
    status ENUM('pending','active','completed','terminated','cancelled')
        NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finalized_at DATETIME NULL,
    terminated_at DATETIME NULL,
    FOREIGN KEY (application_id) REFERENCES rental_applications(application_id) ON DELETE SET NULL,
    FOREIGN KEY (property_id) REFERENCES properties(property_id),
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    FOREIGN KEY (landlord_id) REFERENCES users(user_id),
    INDEX idx_agreement_student (student_id, status),
    INDEX idx_agreement_landlord (landlord_id, status),
    INDEX idx_agreement_property (property_id, status)
) ENGINE=InnoDB;

-- ============================================================
-- 7. PAYMENTS / RECEIPTS
-- ============================================================

CREATE TABLE payments (
    payment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    agreement_id BIGINT UNSIGNED NULL,
    application_id BIGINT UNSIGNED NULL,
    student_id INT UNSIGNED NOT NULL,
    landlord_id INT UNSIGNED NOT NULL,
    property_id INT UNSIGNED NOT NULL,
    payment_type ENUM('rent','security_deposit','service_charge','other') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    method ENUM('bkash','nagad','rocket','bank_transfer','cash','card','demo') NOT NULL,
    transaction_reference VARCHAR(120) UNIQUE,
    payment_month DATE NULL,
    status ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    paid_at DATETIME NULL,
    notes VARCHAR(500),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE SET NULL,
    FOREIGN KEY (application_id) REFERENCES rental_applications(application_id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    FOREIGN KEY (landlord_id) REFERENCES users(user_id),
    FOREIGN KEY (property_id) REFERENCES properties(property_id),
    INDEX idx_payment_student (student_id, status, paid_at),
    INDEX idx_payment_landlord (landlord_id, status, paid_at),
    INDEX idx_payment_month (payment_month, status)
) ENGINE=InnoDB;

CREATE TABLE payment_receipts (
    receipt_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    receipt_number VARCHAR(50) NOT NULL UNIQUE,
    issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_to_tenant BOOLEAN NOT NULL DEFAULT FALSE,
    downloaded_at DATETIME NULL,
    pdf_path VARCHAR(500) NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(payment_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 8. MAINTENANCE
-- ============================================================

CREATE TABLE maintenance_categories (
    category_id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO maintenance_categories (category_name) VALUES
('Electrical'),('Plumbing'),('Structural'),('Security'),('Cleaning'),('Other');

CREATE TABLE maintenance_requests (
    request_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    landlord_id INT UNSIGNED NOT NULL,
    category_id TINYINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NOT NULL,
    priority ENUM('Low','Medium','High','Urgent') NOT NULL DEFAULT 'Medium',
    status ENUM('Pending','In Progress','Resolved','Rejected','Cancelled')
        NOT NULL DEFAULT 'Pending',
    attachment_path VARCHAR(500) NULL,
    landlord_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by INT UNSIGNED NULL,
    landlord_note TEXT,
    FOREIGN KEY (property_id) REFERENCES properties(property_id),
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    FOREIGN KEY (landlord_id) REFERENCES users(user_id),
    FOREIGN KEY (category_id) REFERENCES maintenance_categories(category_id),
    FOREIGN KEY (resolved_by) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_maintenance_landlord (landlord_id, status, priority),
    INDEX idx_maintenance_student (student_id, status),
    INDEX idx_maintenance_property (property_id, status)
) ENGINE=InnoDB;

CREATE TABLE maintenance_status_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(30),
    new_status VARCHAR(30) NOT NULL,
    changed_by INT UNSIGNED NULL,
    note VARCHAR(500),
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES maintenance_requests(request_id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 9. CHAT / MESSAGING
-- ============================================================

CREATE TABLE conversations (
    conversation_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at DATETIME NULL,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE SET NULL,
    INDEX idx_conversation_last (last_message_at)
) ENGINE=InnoDB;

CREATE TABLE conversation_participants (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_at DATETIME NULL,
    is_archived BOOLEAN NOT NULL DEFAULT FALSE,
    PRIMARY KEY (conversation_id, user_id),
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE messages (
    message_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id INT UNSIGNED NOT NULL,
    message_text TEXT NOT NULL,
    attachment_path VARCHAR(500) NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    deleted_at DATETIME NULL,
    FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id),
    INDEX idx_messages_conversation (conversation_id, sent_at),
    INDEX idx_messages_sender (sender_id, sent_at)
) ENGINE=InnoDB;

-- ============================================================
-- 10. REVIEWS / LANDLORD REPLIES
-- ============================================================

CREATE TABLE reviews (
    review_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    landlord_reply TEXT NULL,
    replied_at DATETIME NULL,
    status ENUM('published','hidden','pending') NOT NULL DEFAULT 'published',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(property_id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(user_id),
    CONSTRAINT chk_review_rating CHECK (rating BETWEEN 1 AND 5),
    UNIQUE KEY uq_student_property_review (property_id, student_id),
    INDEX idx_review_property (property_id, status),
    INDEX idx_review_student (student_id)
) ENGINE=InnoDB;

CREATE TABLE review_replies (
    reply_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    review_id BIGINT UNSIGNED NOT NULL UNIQUE,
    landlord_id INT UNSIGNED NOT NULL,
    reply_text TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (review_id) REFERENCES reviews(review_id) ON DELETE CASCADE,
    FOREIGN KEY (landlord_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

-- ============================================================
-- 11. NOTIFICATIONS
-- ============================================================

CREATE TABLE notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    notification_type ENUM(
        'application','payment','maintenance','message','review',
        'verification','property','system'
    ) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    reference_type VARCHAR(50),
    reference_id BIGINT UNSIGNED NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    INDEX idx_notifications_user (user_id, is_read, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 12. ADMIN / PLATFORM SETTINGS
-- ============================================================

CREATE TABLE platform_settings (
    setting_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO platform_settings (setting_key, setting_value) VALUES
('platform_name','UIU Student Rental System'),
('support_email','support@uiu-rental.local'),
('commission_rate','5'),
('default_currency','BDT'),
('minimum_student_age','18'),
('ui_distance_reference','UIU Main Gate');

CREATE TABLE audit_logs (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT UNSIGNED NULL,
    action_type VARCHAR(80) NOT NULL,
    table_name VARCHAR(80),
    record_id BIGINT UNSIGNED NULL,
    old_data JSON NULL,
    new_data JSON NULL,
    ip_address VARCHAR(45),
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_audit_actor_date (actor_user_id, created_at),
    INDEX idx_audit_table_record (table_name, record_id)
) ENGINE=InnoDB;

-- ============================================================
-- 13. CONTACT / SUPPORT
-- ============================================================

CREATE TABLE contact_messages (
    contact_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
    assigned_to INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (assigned_to) REFERENCES users(user_id) ON DELETE SET NULL,
    INDEX idx_contact_status (status, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- 14. TRIGGERS (REMOVED)
-- Automation logic (audit logging, notification alerts, receipt generation)
-- is now handled by the PHP backend application layer.
-- ============================================================

-- ============================================================
-- 15. VIEWS (REMOVED)
-- All aggregate queries, search listings, dashboards, and standard table queries
-- are executed via direct SQL queries inside the PHP backend application layer.
-- ============================================================

-- ============================================================
-- 16. STORED PROCEDURES (REMOVED)
-- Property search, application review, maintenance submission,
-- payment processing, and admin decisions are handled by PHP backend endpoints.
-- ============================================================

-- ============================================================
-- 17. STORED FUNCTIONS (REMOVED)
-- Rating calculations, revenue aggregations, and unread notification
-- counts are executed directly in PHP backend SQL queries.
-- ============================================================

-- ============================================================
-- 18. DEMO USERS
-- Password seed below is intentionally simple for the demo.
-- In real PHP code use password_hash()/password_verify().
-- Demo password for all seeded accounts: password
-- ============================================================

INSERT INTO users
(role_id, full_name, email, phone, password_hash, account_status, email_verified, phone_verified)
VALUES
((SELECT role_id FROM roles WHERE role_name='student'),
 'Rakib Hassan','rakib@uiu.ac.bd','+8801712345678',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Kamal Ahmed','kamal@gmail.com','+8801711223344',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Abdul Kader','abdulkader99@gmail.com','+8801812345678',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'pending',FALSE,FALSE),

((SELECT role_id FROM roles WHERE role_name='student'),
 'Mehedi Islam','mehedi@uiu.ac.bd','+8801912345678',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'pending',FALSE,FALSE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Rafiqul Islam','rafiqul@gmail.com','+8801678901234',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4o3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Mahmudur Rahman','mahmudur@gmail.com','+8801755667788',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Shah Alam','shahalam@gmail.com','+8801833445566',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Jahangir Hossain','jahangir@gmail.com','+8801922334455',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Mizanur Rahman','mizanur@gmail.com','+8801744556677',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='landlord'),
 'Nusrat Jahan','nusrat@gmail.com','+8801866778899',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE),

((SELECT role_id FROM roles WHERE role_name='admin'),
 'Admin User','admin@uiu.ac.bd','+8801700000000',
 '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC2aYQf0eG0bZ8Jk1e7K',
 'active',TRUE,TRUE);

INSERT INTO student_profiles
(student_id, university_student_id, program, permanent_address, verification_status)
VALUES
(1,'0112300001','BSc in CSE','Dhaka, Bangladesh','verified'),
(4,'0112299988','BSc in CSE','Dhaka, Bangladesh','pending');

INSERT INTO landlord_profiles
(landlord_id,nid_number,permanent_address,verification_status,member_since)
VALUES
(2,'1234567890','Badda, Dhaka','verified','2021-01-15'),
(3,'9876543210','Dhaka','pending','2026-01-01'),
(5,'NID-RI-2019','Shahjadpur, Dhaka','verified','2019-01-01'),
(6,'NID-MR-2018','Badda, Dhaka','verified','2018-01-01'),
(7,'NID-SA-2023','Aftabnagar, Dhaka','verified','2023-01-01'),
(8,'NID-JH-2021','Badda, Dhaka','verified','2021-01-01'),
(9,'NID-MZ-2022','Merul Badda, Dhaka','verified','2022-01-01'),
(10,'NID-NJ-2020','Shahjadpur, Dhaka','verified','2020-01-01');

-- ============================================================
-- 19. DEMO PROPERTIES FROM FRONTEND PROPERTY_DATA
-- ============================================================

INSERT INTO properties
(landlord_id,category_id,title,description,neighborhood,full_address,city,
 distance_from_uiu_km,floor_label,bedroom_count,bathroom_count,area_sqft,
 monthly_rent,security_deposit,service_charge,minimum_stay_months,available_from,
 house_rules,status,verified)
VALUES
(2,(SELECT category_id FROM property_categories WHERE category_name='Studio Apartment'),
 'Modern Studio Apartment','Modern studio apartment suitable for a UIU student.',
 'Badda','Badda, Dhaka','Dhaka',0.30,'4th Floor',1,1,650,8500,8500,500,6,'2026-10-01',
 'No smoking inside. Keep common areas clean.','approved',TRUE),

(2,(SELECT category_id FROM property_categories WHERE category_name='2 Bedroom Flat'),
 'Furnished 2-Bed Flat','Furnished two-bedroom flat near UIU.',
 'Rampura','Rampura, Dhaka','Dhaka',0.60,'3rd Floor',2,2,950,14000,14000,700,6,'2026-10-05',
 'Family/student friendly. No loud noise after 11 PM.','approved',TRUE),

(2,(SELECT category_id FROM property_categories WHERE category_name='Single Room'),
 'Single Room with Kitchen','Private single room with kitchen access.',
 'Merul Badda','Merul Badda, Dhaka','Dhaka',0.20,'2nd Floor',1,1,400,6000,6000,300,3,'2026-10-01',
 'Students only.','approved',TRUE),

(5,(SELECT category_id FROM property_categories WHERE category_name='Mess / Shared Room'),
 'Cozy Mess Room','Affordable shared mess room for students.',
 'Shahjadpur','Shahjadpur, Dhaka','Dhaka',0.80,'1st Floor',1,1,250,4500,4500,250,3,'2026-10-01',
 'Shared kitchen and common space.','approved',TRUE),

(6,(SELECT category_id FROM property_categories WHERE category_name='3 Bedroom Flat'),
 'Spacious 3-Bed Family Flat','Large three-bedroom flat with balcony.',
 'Badda','Badda, Dhaka','Dhaka',1.10,'5th Floor',3,3,1400,22000,22000,1000,12,'2026-11-01',
 'No sublet.','approved',TRUE),

(7,(SELECT category_id FROM property_categories WHERE category_name='Sublet'),
 'Student Sublet Near UIU','Budget sublet suitable for students.',
 'Aftabnagar','Aftabnagar, Dhaka','Dhaka',0.40,'3rd Floor',1,1,350,5500,5500,200,3,'2026-10-01',
 'Short-term student sublet.','approved',TRUE),

(8,(SELECT category_id FROM property_categories WHERE category_name='2 Bedroom Flat'),
 'Premium 2-Bed with Balcony','Premium two-bedroom apartment with balcony.',
 'Badda','Badda, Dhaka','Dhaka',0.50,'6th Floor',2,2,1050,17500,17500,800,6,'2026-10-10',
 'No smoking.','approved',TRUE),

(9,(SELECT category_id FROM property_categories WHERE category_name='Single Room'),
 'Budget Single Room','Low-cost single room for UIU students.',
 'Merul Badda','Merul Badda, Dhaka','Dhaka',0.90,'4th Floor',1,1,200,3800,3800,150,3,'2026-10-01',
 'Students only.','approved',TRUE),

(10,(SELECT category_id FROM property_categories WHERE category_name='Mess / Shared Room'),
 'Girls Mess (2nd Floor)','Girls-only mess accommodation.',
 'Shahjadpur','Shahjadpur, Dhaka','Dhaka',0.60,'2nd Floor',1,1,300,5200,5200,250,3,'2026-10-01',
 'Girls only.','approved',TRUE);

-- Extra pending listing for admin approval workflow
INSERT INTO properties
(landlord_id,category_id,title,description,neighborhood,full_address,city,
 distance_from_uiu_km,floor_label,bedroom_count,bathroom_count,area_sqft,
 monthly_rent,security_deposit,service_charge,minimum_stay_months,available_from,
 house_rules,status,verified)
VALUES
(3,(SELECT category_id FROM property_categories WHERE category_name='Sublet'),
 'Pending Student Sublet','New listing awaiting admin verification.',
 'Badda','House 12, Road 3, Block C, Badda, Dhaka','Dhaka',0.50,'2nd Floor',
 1,1,350,6500,6500,200,3,'2026-10-15','Verification required before publishing.','pending',FALSE);

-- ============================================================
-- 20. DEMO PROPERTY IMAGES
-- ============================================================

INSERT INTO property_images(property_id,image_url,is_primary,sort_order) VALUES
(1,'photo-1560448204-e02f11c3d0e2',TRUE,1),
(1,'photo-1522708323590-d24dbb6b0267',FALSE,2),
(1,'photo-1493809842364-78817add7ffb',FALSE,3),
(1,'photo-1502672260266-1c1ef2d93688',FALSE,4),
(1,'photo-1484154218962-a197022b5858',FALSE,5),
(2,'photo-1522708323590-d24dbb6b0267',TRUE,1),
(3,'photo-1493809842364-78817add7ffb',TRUE,1),
(4,'photo-1502672260266-1c1ef2d93688',TRUE,1),
(5,'photo-1484154218962-a197022b5858',TRUE,1),
(6,'photo-1556020685-ae41abfc9365',TRUE,1),
(7,'photo-1484101403633-562f891dc89a',TRUE,1),
(8,'photo-1515263487990-61b07816b324',TRUE,1),
(9,'photo-1554995207-c18c203602cb',TRUE,1);

-- ============================================================
-- 21. DEMO FACILITY ASSIGNMENTS
-- ============================================================

INSERT INTO property_facilities(property_id,facility_id)
SELECT 1, facility_id FROM facilities WHERE facility_name IN
('Wi-Fi','AC','Attached Bath','Kitchen','Lift','Security','CCTV','Water Supply','Electricity Backup','Furnished');

INSERT INTO property_facilities(property_id,facility_id)
SELECT 2, facility_id FROM facilities WHERE facility_name IN
('Wi-Fi','AC','Attached Bath','Kitchen','Lift','Parking','Security','CCTV','Water Supply','Furnished','Balcony');

INSERT INTO property_facilities(property_id,facility_id)
SELECT 3, facility_id FROM facilities WHERE facility_name IN
('Wi-Fi','Kitchen','Shared Bath','Security','Water Supply','Study Table');

INSERT INTO property_facilities(property_id,facility_id)
SELECT 4, facility_id FROM facilities WHERE facility_name IN
('Wi-Fi','Dining','Shared Bath','Security','Water Supply','Gas');

INSERT INTO property_facilities(property_id,facility_id)
SELECT 5, facility_id FROM facilities WHERE facility_name IN
('Wi-Fi','AC','Attached Bath','Kitchen','Lift','Parking','Security','CCTV','Generator','Balcony');

-- ============================================================
-- 22. DEMO REVIEWS
-- ============================================================

INSERT INTO reviews(property_id,student_id,rating,comment,created_at) VALUES
(1,1,5,'Very clean room and the location is excellent for UIU students.','2026-08-10 12:00:00'),
(1,4,4,'Good place and landlord communication is helpful.','2026-08-20 12:00:00'),
(2,1,5,'Spacious and convenient.','2026-08-25 12:00:00'),
(3,1,4,'Affordable for a single student.','2026-08-28 12:00:00');

INSERT INTO review_replies(review_id,landlord_id,reply_text) VALUES
(1,2,'Thank you for the review. We are glad you liked the location.'),
(2,2,'Thank you. We will continue improving the service.');

-- ============================================================
-- 23. DEMO APPLICATIONS
-- ============================================================

INSERT INTO rental_applications
(property_id,student_id,move_in_date,duration_months,message,status,applied_at)
VALUES
(1,1,'2026-10-01',6,'I am interested in renting this studio near UIU.','approved','2026-09-20 10:00:00'),
(2,4,'2026-10-05',6,'Please consider my application.','pending','2026-09-28 14:00:00');

-- ============================================================
-- 24. DEMO ACTIVE RENTAL
-- ============================================================

INSERT INTO rental_agreements
(application_id,property_id,student_id,landlord_id,start_date,end_date,
 duration_months,monthly_rent,security_deposit,service_charge,terms,status,finalized_at)
VALUES
(1,1,1,2,'2026-10-01','2027-03-31',6,8500,8500,500,
 'Monthly rent is due within the first 7 days of each month.','active','2026-09-25 10:00:00');

UPDATE properties SET status='rented' WHERE property_id=1;

-- ============================================================
-- 25. DEMO PAYMENTS / RECEIPTS
-- ============================================================

INSERT INTO payments
(agreement_id,student_id,landlord_id,property_id,payment_type,amount,method,
 transaction_reference,payment_month,status,paid_at)
VALUES
(1,1,2,1,'security_deposit',8500,'bkash','TXN-BK-10001','2026-10-01','paid','2026-09-28 12:00:00'),
(1,1,2,1,'rent',8500,'bkash','TXN-BK-10002','2026-10-01','paid','2026-10-01 09:00:00'),
(1,1,2,1,'service_charge',500,'bkash','TXN-BK-10003','2026-10-01','paid','2026-10-01 09:05:00');

-- Trigger-generated receipts are created automatically.

-- ============================================================
-- 26. DEMO MAINTENANCE
-- ============================================================

INSERT INTO maintenance_requests
(property_id,student_id,landlord_id,category_id,title,description,priority,status,created_at)
VALUES
(1,1,1,1,'AC issue','The AC is not cooling properly.','High','In Progress','2026-09-29 09:30:00'),
(1,1,2,2,'Broken water tap','Kitchen water tap is leaking.','Medium','Pending','2026-09-30 15:00:00'),
(1,1,2,3,'Door lock issue','Main room lock needs checking.','Urgent','Resolved','2026-09-20 10:00:00');

UPDATE maintenance_requests
SET resolved_at='2026-09-21 12:00:00', resolved_by=2, landlord_note='Lock replaced successfully.'
WHERE request_id=3;

-- ============================================================
-- 27. DEMO CHAT
-- ============================================================

INSERT INTO conversations(property_id) VALUES (1),(2);

INSERT INTO conversation_participants(conversation_id,user_id) VALUES
(1,1),(1,2),(2,4),(2,2);

INSERT INTO messages(conversation_id,sender_id,message_text,sent_at) VALUES
(1,2,'Hello Rakib, how can I help you regarding the apartment?','2026-09-29 09:00:00'),
(1,1,'I wanted to confirm the move-in date.','2026-09-29 09:05:00'),
(1,2,'The apartment will be ready from October 1.','2026-09-29 09:07:00'),
(2,4,'Hello, is the 2-bed flat still available?','2026-09-30 18:00:00');

-- ============================================================
-- 28. DEMO NOTIFICATIONS
-- ============================================================

INSERT INTO notifications(user_id,notification_type,title,message,reference_type,reference_id)
VALUES
(1,'application','Application Approved','Your application for Modern Studio Apartment has been approved.','application',1),
(1,'payment','Payment Successful','Your October rent payment has been recorded.','payment',2),
(1,'maintenance','Maintenance Update','Your AC maintenance request is in progress.','maintenance',1),
(2,'property','New Application','A student submitted an application for your property.','application',2),
(2,'review','New Property Review','A new review was posted for your property.','review',1);

-- ============================================================
-- 29. DEMO CONTACT MESSAGE
-- ============================================================

INSERT INTO contact_messages(name,email,subject,message)
VALUES
('Demo Student','student@uiu.ac.bd','Need help with rental application',
 'I need help understanding the application process.');

-- ============================================================
-- 30. COMMON REPORT QUERIES
-- ============================================================

-- SELECT: approved properties
-- SELECT * FROM properties WHERE status='approved';

-- Search/filter equivalent to frontend browse page
-- SELECT * FROM properties WHERE status='approved' AND monthly_rent <= 25000;

-- JOIN: student applications
-- SELECT * FROM rental_applications WHERE student_id=1;

-- Aggregate: landlord revenue
-- SELECT landlord_id, SUM(amount) total_revenue
-- FROM payments WHERE status='paid' GROUP BY landlord_id;

-- HAVING: landlords with revenue >= 10000
-- SELECT landlord_id, SUM(amount) total_revenue
-- FROM payments WHERE status='paid'
-- GROUP BY landlord_id HAVING SUM(amount)>=10000;

-- Subquery: properties above average rent
-- SELECT * FROM properties
-- WHERE monthly_rent > (SELECT AVG(monthly_rent) FROM properties);

-- ============================================================
-- 31. SECURITY / FK RESET
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- END OF DATABASE
-- ============================================================
