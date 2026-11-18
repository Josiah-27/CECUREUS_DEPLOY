-- LOCAL DEVELOPMENT ONLY. Fictional demo data so every endpoint returns something.
-- Run after 000_existing_tables_structure.sql and ../001_mobile_api_tables.sql.
-- Accounts are NOT seeded: create one through the app (or bin/smoke-test.php) with SMS_DRIVER=log.

INSERT INTO `doctor_list`
  (`account_id`, `doc_statname`, `doc_city`, `doc_cityname`, `doc_otp`, `doc_name`, `zoomid`, `zoomstatus`, `doc_email`,
   `doc_password`, `doc_mobile`, `doc_fee`, `doc_address`, `doc_gst_no`, `doc_pannumber`, `doc_prelanguage`, `doc_maristatus`,
   `doc_experiance`, `doc_service`, `doc_areaexport`, `doc_description`, `doct_image`, `doc_category`, `bank_acc_number`,
   `bank_name`, `bank_branch`, `bank_ifsc`, `bank_account_type`, `reg_date`, `created_at`, `updated_at`, `status`)
VALUES
  ('', 'Tamil Nadu', '', 'Chennai', '', 'Demo Counsellor One', '', '', 'private-do-not-expose@example.com',
   'secret-hash-do-not-expose', '9000000001', '', '', '', 'PAN-DO-NOT-EXPOSE', 'English, Tamil', '',
   '8 Years', '', '', '<p>Clinical psychologist (demo profile).</p>', '', 'Anxiety Management, Stress Management', '000000000000',
   '', '', '', '', '01-01-2026', '01-01-2026 10:00:00 AM', '01-01-2026 10:00:00 AM', 'Active'),
  ('', 'Maharashtra', '', 'Mumbai', '', 'Demo Counsellor Two', '', '', 'private2@example.com',
   'secret-hash-do-not-expose', '9000000002', '', '', '', '', 'English, Hindi', '',
   '6', '', '', 'Counselling psychologist (demo profile, default hours).', '', 'Workplace Stress, Burnout', '',
   '', '', '', '', '01-01-2026', '01-01-2026 10:00:00 AM', '01-01-2026 10:00:00 AM', 'Active'),
  ('', '', '', 'Delhi', '', 'Inactive Counsellor', '', '', '', '', '', '', '', '', '', 'English', '',
   '3', '', '', 'Must never appear in the app.', '', 'Anxiety', '', '', '', '', '', '', '', '', 'DeActive');

-- Counsellor 1 works Mon–Sat 10 AM–1 PM and 2 PM–6 PM. Counsellor 2 has no row → AVAILABILITY_DEFAULT_HOURS.
INSERT INTO `doctor_timelist`
  (`doc_id`,
   `monday`, `doc_mondayfirst_from`, `doc_mondayfirst_to`, `doc_mondaysecond_from`, `doc_mondaysecond_to`,
   `tuesday`, `doc_tuesdayfirst_from`, `doc_tuesdayfirst_to`, `doc_tuesdaysecond_from`, `doc_tuesdaysecond_to`,
   `wednesday`, `doc_wednesdayfirst_from`, `doc_wednesdayfirst_to`, `doc_wednesdaysecond_from`, `doc_wednesdaysecond_to`,
   `thursday`, `doc_thursdayfirst_from`, `doc_thursdayfirst_to`, `doc_thursdaysecond_from`, `doc_thursdaysecond_to`,
   `friday`, `doc_fridayfirst_from`, `doc_fridayfirst_to`, `doc_fridaysecond_from`, `doc_fridaysecond_to`,
   `saturday`, `doc_saturdayfirst_from`, `doc_saturdayfirst_to`, `doc_saturdaysecond_from`, `doc_saturdaysecond_to`,
   `sunday`, `doc_sundayfirst_from`, `doc_sundayfirst_to`, `doc_sundaysecond_from`, `doc_sundaysecond_to`,
   `created_at`, `updated_at`, `status`)
VALUES
  ('1',
   'Monday', '10:00 AM', '1:00 PM', '2:00 PM', '6:00 PM',
   'Tuesday', '10:00 AM', '1:00 PM', '2:00 PM', '6:00 PM',
   'Wednesday', '10:00 AM', '1:00 PM', '2:00 PM', '6:00 PM',
   'Thursday', '10:00 AM', '1:00 PM', '2:00 PM', '6:00 PM',
   'Friday', '10:00 AM', '1:00 PM', '2:00 PM', '6:00 PM',
   'Saturday', '10:00 AM', '1:00 PM', '', '',
   '', '', '', '', '',
   '', '', 'Active');

INSERT INTO `doct_language` (`doc_id`, `language`) VALUES ('1', 'English'), ('1', 'Tamil');

INSERT INTO `service` (`service_cost`, `service_time`, `service_buffer`, `created_at`, `updated_at`, `status`)
VALUES ('0', '60 Minutes', '0 Minutes', '', '', 'Active');

INSERT INTO `issuse_list` (`issuse_name`, `created_at`, `updated_at`, `status`) VALUES
  ('Stress', '', '', 'Active'), ('Anxiety', '', '', 'Active'), ('Workplace Stress', '', '', 'Active'), ('Old issue', '', '', 'DeActive');

INSERT INTO `language` (`language_name`, `role`, `created_at`, `updated_at`) VALUES
  ('English', '', '', ''), ('Hindi', '', '', ''), ('Tamil', '', '', '');

INSERT INTO `blog_post` (`blogcat_id`, `blog_cateory`, `post_category`, `post_image`, `post_content`, `created_at`, `updated_at`, `status`) VALUES
  ('1', 'Stress', '5 Signs You Might Be Overwhelmed', '', '<p>Feeling stretched thin? Here are five signs to watch for.</p><p>1. Sleep changes&hellip;</p>', '05-01-2026 09:30:00 AM', '', 'Active'),
  ('2', 'Anxiety', 'How to Manage Anxiety at Work', '', '<p>Practical steps for anxious days at work.</p>', '06-01-2026 11:00:00 AM', '', 'Active');

INSERT INTO `quiz_category` (`quiz_category`, `quiz_slug`, `quiz_content`, `quiz_image`, `created_at`, `updated_at`, `status`) VALUES
  ('Stress Level Check', 'stress-level-check', '<p>Find out your current stress level.</p>', '', '', '', 'Active');

INSERT INTO `quiz_list` (`quizcat_id`, `quiz_category`, `quiz_question`, `created_at`, `updated_at`, `status`) VALUES
  ('1', 'Stress Level Check', 'How often do you feel overwhelmed?', '', '', 'Active'),
  ('1', 'Stress Level Check', 'How well do you sleep?', '', '', 'Active');

INSERT INTO `quiz_answer` (`quiz_id`, `quiz_ansfour`, `quiz_ansfourtick`, `created_at`) VALUES
  ('1', 'Rarely', '0', ''), ('1', 'Sometimes', '1', ''), ('1', 'Often', '2', ''),
  ('2', 'Well', '0', ''), ('2', 'Not great', '1', ''), ('2', 'Poorly', '2', '');

INSERT INTO `percentage` (`quizcat_id`, `per_title`, `session`, `per_titleto`, `sessiontwo`, `per_content`, `created_at`, `updated_at`) VALUES
  ('1', '0', 'Low', '33', '', '<p>Your stress level looks manageable.</p>', '', ''),
  ('1', '34', 'Moderate', '66', '', '<p>Some stress is building up. Try a breathing exercise or talk to a counsellor.</p>', '', ''),
  ('1', '67', 'High', '100', '', '<p>Your stress level is high. Consider booking a session.</p>', '', '');
