-- LOCAL DEVELOPMENT ONLY. Do NOT run on the real database (it already has these tables).
-- Structure (no data) of the existing CecureUs tables the mobile API uses, taken from the staging dump,
-- with each table's primary key / AUTO_INCREMENT folded in. Engines and charsets are kept as they are
-- in production (MyISAM, latin1) so local behaviour matches.

DROP TABLE IF EXISTS `employee_list`;
CREATE TABLE `employee_list` (
  `emp_id` int(250) NOT NULL AUTO_INCREMENT,
  `emp_otp` varchar(250) NOT NULL,
  `emp_companyname` varchar(250) NOT NULL,
  `emp_name` varchar(250) NOT NULL,
  `emp_lname` varchar(250) NOT NULL,
  `emp_companyid` varchar(250) NOT NULL,
  `emp_email` varchar(250) NOT NULL,
  `emp_password` varchar(500) NOT NULL,
  `emp_mobile` varchar(250) NOT NULL,
  `emp_familyoption` varchar(250) NOT NULL,
  `emp_dob` varchar(250) NOT NULL,
  `emp_age` varchar(250) NOT NULL,
  `emp_gender` varchar(250) NOT NULL,
  `emp_country` varchar(250) NOT NULL,
  `emp_option` varchar(250) NOT NULL,
  `emp_location` varchar(250) NOT NULL,
  `emp_jobtitle` varchar(250) NOT NULL,
  `emp_department` varchar(250) NOT NULL,
  `emp_doj` varchar(250) NOT NULL,
  `emp_agree` varchar(250) NOT NULL,
  `emp_organisationname` varchar(250) NOT NULL,
  `emp_maritalstatus` varchar(250) NOT NULL,
  `emp_emargenumber` varchar(250) NOT NULL,
  `plan_date` varchar(250) NOT NULL,
  `reg_date` varchar(250) NOT NULL,
  `emp_role` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  `position` varchar(250) NOT NULL,
  PRIMARY KEY (`emp_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `doctor_list`;
CREATE TABLE `doctor_list` (
  `doc_id` int(250) NOT NULL AUTO_INCREMENT,
  `account_id` varchar(250) NOT NULL,
  `doc_statname` varchar(250) NOT NULL,
  `doc_city` varchar(250) NOT NULL,
  `doc_cityname` varchar(250) NOT NULL,
  `doc_otp` varchar(250) NOT NULL,
  `doc_name` varchar(250) NOT NULL,
  `zoomid` varchar(255) NOT NULL,
  `zoomstatus` varchar(255) NOT NULL,
  `doc_email` varchar(250) NOT NULL,
  `doc_password` varchar(250) NOT NULL,
  `doc_mobile` varchar(250) NOT NULL,
  `doc_fee` varchar(250) NOT NULL,
  `doc_address` varchar(250) NOT NULL,
  `doc_gst_no` varchar(250) NOT NULL,
  `doc_pannumber` varchar(250) NOT NULL,
  `doc_prelanguage` varchar(250) NOT NULL,
  `doc_maristatus` varchar(250) NOT NULL,
  `doc_experiance` varchar(250) NOT NULL,
  `doc_service` varchar(250) NOT NULL,
  `doc_areaexport` blob NOT NULL,
  `doc_description` blob NOT NULL,
  `doct_image` varchar(250) NOT NULL,
  `doc_category` varchar(255) NOT NULL,
  `bank_acc_number` varchar(250) NOT NULL,
  `bank_name` varchar(250) NOT NULL,
  `bank_branch` varchar(250) NOT NULL,
  `bank_ifsc` varchar(250) NOT NULL,
  `bank_account_type` varchar(250) NOT NULL,
  `reg_date` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`doc_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `doct_language`;
CREATE TABLE `doct_language` (
  `id` int(250) NOT NULL AUTO_INCREMENT,
  `doc_id` varchar(250) NOT NULL,
  `language` varchar(250) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `language`;
CREATE TABLE `language` (
  `id` int(250) NOT NULL AUTO_INCREMENT,
  `language_name` varchar(250) NOT NULL,
  `role` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `doctor_timelist`;
CREATE TABLE `doctor_timelist` (
  `id` int(250) NOT NULL AUTO_INCREMENT,
  `doc_id` varchar(250) NOT NULL,
  `monday` varchar(250) NOT NULL,
  `doc_mondayfirst_from` varchar(250) NOT NULL,
  `doc_mondayfirst_to` varchar(250) NOT NULL,
  `doc_mondaysecond_from` varchar(250) NOT NULL,
  `doc_mondaysecond_to` varchar(250) NOT NULL,
  `tuesday` varchar(250) NOT NULL,
  `doc_tuesdayfirst_from` varchar(250) NOT NULL,
  `doc_tuesdayfirst_to` varchar(250) NOT NULL,
  `doc_tuesdaysecond_from` varchar(250) NOT NULL,
  `doc_tuesdaysecond_to` varchar(250) NOT NULL,
  `wednesday` varchar(250) NOT NULL,
  `doc_wednesdayfirst_from` varchar(250) NOT NULL,
  `doc_wednesdayfirst_to` varchar(250) NOT NULL,
  `doc_wednesdaysecond_from` varchar(250) NOT NULL,
  `doc_wednesdaysecond_to` varchar(250) NOT NULL,
  `thursday` varchar(250) NOT NULL,
  `doc_thursdayfirst_from` varchar(250) NOT NULL,
  `doc_thursdayfirst_to` varchar(250) NOT NULL,
  `doc_thursdaysecond_from` varchar(250) NOT NULL,
  `doc_thursdaysecond_to` varchar(250) NOT NULL,
  `friday` varchar(250) NOT NULL,
  `doc_fridayfirst_from` varchar(250) NOT NULL,
  `doc_fridayfirst_to` varchar(250) NOT NULL,
  `doc_fridaysecond_from` varchar(250) NOT NULL,
  `doc_fridaysecond_to` varchar(250) NOT NULL,
  `saturday` varchar(250) NOT NULL,
  `doc_saturdayfirst_from` varchar(250) NOT NULL,
  `doc_saturdayfirst_to` varchar(250) NOT NULL,
  `doc_saturdaysecond_from` varchar(250) NOT NULL,
  `doc_saturdaysecond_to` varchar(250) NOT NULL,
  `sunday` varchar(250) NOT NULL,
  `doc_sundayfirst_from` varchar(250) NOT NULL,
  `doc_sundayfirst_to` varchar(250) NOT NULL,
  `doc_sundaysecond_from` varchar(250) NOT NULL,
  `doc_sundaysecond_to` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `service`;
CREATE TABLE `service` (
  `ser_id` int(250) NOT NULL AUTO_INCREMENT,
  `service_cost` varchar(250) NOT NULL,
  `service_time` varchar(250) NOT NULL,
  `service_buffer` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`ser_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `booking_appointment`;
CREATE TABLE `booking_appointment` (
  `book_id` int(250) NOT NULL AUTO_INCREMENT,
  `purchase_id` varchar(250) NOT NULL,
  `customer_id` varchar(250) NOT NULL,
  `doc_id` varchar(250) NOT NULL,
  `doc_name` varchar(250) NOT NULL,
  `service_time` varchar(250) NOT NULL,
  `issuse_name` varchar(250) NOT NULL,
  `speaking_language` varchar(250) NOT NULL,
  `familyoption` varchar(250) NOT NULL,
  `connectoption` varchar(250) NOT NULL,
  `booknewdate` varchar(250) NOT NULL,
  `zoom_id` varchar(250) NOT NULL,
  `host_id` varchar(250) NOT NULL,
  `start_url` blob NOT NULL,
  `join_url` blob NOT NULL,
  `meating_timelist` varchar(250) NOT NULL,
  `meating_starttime` varchar(250) DEFAULT NULL,
  `meating_endtime` varchar(250) NOT NULL,
  `appointment_notes` blob NOT NULL,
  `review_rating` varchar(250) DEFAULT '0',
  `review_comments` blob NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  `bookedby` varchar(255) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `canceledby` varchar(255) NOT NULL,
  `access_status` varchar(250) NOT NULL,
  `role` varchar(250) NOT NULL,
  `popupcount` int(11) NOT NULL,
  `remainder_status` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`book_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `issuse_list`;
CREATE TABLE `issuse_list` (
  `issuse_id` int(250) NOT NULL AUTO_INCREMENT,
  `issuse_name` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`issuse_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `blog_post`;
CREATE TABLE `blog_post` (
  `post_id` int(250) NOT NULL AUTO_INCREMENT,
  `blogcat_id` varchar(250) NOT NULL,
  `blog_cateory` varchar(250) NOT NULL,
  `post_category` varchar(250) NOT NULL,
  `post_image` varchar(250) NOT NULL,
  `post_content` blob NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`post_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `quiz_category`;
CREATE TABLE `quiz_category` (
  `quizcat_id` int(250) NOT NULL AUTO_INCREMENT,
  `quiz_category` varchar(250) NOT NULL,
  `quiz_slug` varchar(250) NOT NULL,
  `quiz_content` blob NOT NULL,
  `quiz_image` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`quizcat_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `quiz_list`;
CREATE TABLE `quiz_list` (
  `quiz_id` int(250) NOT NULL AUTO_INCREMENT,
  `quizcat_id` varchar(250) NOT NULL,
  `quiz_category` varchar(250) NOT NULL,
  `quiz_question` varchar(300) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  `status` varchar(250) NOT NULL,
  PRIMARY KEY (`quiz_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `quiz_answer`;
CREATE TABLE `quiz_answer` (
  `quiz_ans_id` int(250) NOT NULL AUTO_INCREMENT,
  `quiz_id` varchar(250) NOT NULL,
  `quiz_ansfour` varchar(250) NOT NULL,
  `quiz_ansfourtick` varchar(250) NOT NULL,
  `created_at` varchar(250) NOT NULL,
  PRIMARY KEY (`quiz_ans_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

DROP TABLE IF EXISTS `percentage`;
CREATE TABLE `percentage` (
  `per_id` int(250) NOT NULL AUTO_INCREMENT,
  `quizcat_id` varchar(11) NOT NULL,
  `per_title` varchar(250) NOT NULL,
  `session` varchar(250) NOT NULL,
  `per_titleto` varchar(250) NOT NULL,
  `sessiontwo` varchar(250) NOT NULL,
  `per_content` blob NOT NULL,
  `created_at` varchar(250) NOT NULL,
  `updated_at` varchar(250) NOT NULL,
  PRIMARY KEY (`per_id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

