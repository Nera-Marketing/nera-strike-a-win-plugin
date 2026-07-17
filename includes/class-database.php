<?php
/**
 * Custom-table schema and migrations.
 *
 * All Strikeawin state lives in indexed custom tables (not CPT/postmeta):
 *  - questions        : the global question bank
 *  - question_seen    : per-user non-repeat ledger
 *  - runs             : one paid playthrough (holds the config snapshot)
 *  - run_slots        : per-slot drip/timer/answer audit + fail-rate raw data
 *  - spin_pool        : per-competition available/reserved/confirmed counters
 *  - reservations     : the no-oversell reservation ledger (ADR 0001)
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Database
 */
class Nera_SAW_Database {

	const DB_VERSION = '0.5.0';
	const OPTION_KEY = 'nera_saw_db_version';

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Bare table name (e.g. 'questions').
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'nera_saw_' . $name;
	}

	/**
	 * Run dbDelta if the stored DB version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_KEY ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create/upgrade all tables.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$questions     = self::table( 'questions' );
		$question_seen = self::table( 'question_seen' );
		$runs          = self::table( 'runs' );
		$run_slots     = self::table( 'run_slots' );
		$spin_pool     = self::table( 'spin_pool' );
		$reservations  = self::table( 'reservations' );
		$run_grants    = self::table( 'run_grants' );
		$log           = self::table( 'log' );

		$sql = "
CREATE TABLE {$questions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  level_key varchar(32) NOT NULL,
  category varchar(96) NOT NULL DEFAULT '',
  language varchar(12) NOT NULL DEFAULT 'en',
  question_text text NOT NULL,
  option_a text NOT NULL,
  option_b text NOT NULL,
  option_c text NOT NULL,
  option_d text NOT NULL,
  correct_option char(1) NOT NULL,
  is_active tinyint(1) NOT NULL DEFAULT 1,
  seed_batch_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY draw_idx (language, level_key, category, is_active),
  KEY seed_batch_id (seed_batch_id)
) {$charset_collate};

CREATE TABLE {$question_seen} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  first_seen_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY user_question (user_id, question_id),
  KEY question_id (question_id)
) {$charset_collate};

CREATE TABLE {$runs} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  competition_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tier_key varchar(32) NOT NULL DEFAULT '',
  language varchar(12) NOT NULL DEFAULT 'en',
  config_snapshot longtext NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  correct_count int(10) unsigned NOT NULL DEFAULT 0,
  spins_confirmed int(10) unsigned NOT NULL DEFAULT 0,
  max_possible_spins int(10) unsigned NOT NULL DEFAULT 0,
  end_reason varchar(16) NOT NULL DEFAULT '',
  seed_batch_id varchar(64) DEFAULT NULL,
  created_at datetime NOT NULL,
  started_at datetime DEFAULT NULL,
  finalized_at datetime DEFAULT NULL,
  PRIMARY KEY (id),
  KEY user_id (user_id),
  KEY competition_id (competition_id),
  KEY order_id (order_id),
  KEY status (status),
  KEY seed_batch_id (seed_batch_id)
) {$charset_collate};

CREATE TABLE {$run_slots} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL,
  slot_no int(10) unsigned NOT NULL,
  level_key varchar(32) NOT NULL,
  question_id bigint(20) unsigned NOT NULL DEFAULT 0,
  reward_base int(10) unsigned NOT NULL DEFAULT 0,
  served_at datetime DEFAULT NULL,
  deadline_at datetime DEFAULT NULL,
  answered_at datetime DEFAULT NULL,
  chosen_option char(1) DEFAULT NULL,
  chosen_index int(10) DEFAULT NULL,
  is_correct tinyint(1) DEFAULT NULL,
  outcome varchar(16) DEFAULT NULL,
  spins_awarded int(10) unsigned NOT NULL DEFAULT 0,
  question_snapshot longtext DEFAULT NULL,
  chosen_text text DEFAULT NULL,
  correct_text text DEFAULT NULL,
  awarded_numbers longtext DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY run_slot (run_id, slot_no),
  KEY question_id (question_id)
) {$charset_collate};

CREATE TABLE {$spin_pool} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  cap int(10) unsigned NOT NULL DEFAULT 0,
  available int(10) unsigned NOT NULL DEFAULT 0,
  reserved int(10) unsigned NOT NULL DEFAULT 0,
  confirmed int(10) unsigned NOT NULL DEFAULT 0,
  status varchar(16) NOT NULL DEFAULT 'open',
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY competition_id (competition_id)
) {$charset_collate};

CREATE TABLE {$reservations} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  competition_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL,
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tier_key varchar(32) NOT NULL DEFAULT '',
  amount int(10) unsigned NOT NULL DEFAULT 0,
  state varchar(16) NOT NULL DEFAULT 'held',
  expires_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  KEY competition_id (competition_id),
  KEY order_id (order_id),
  KEY state_expires (state, expires_at)
) {$charset_collate};

CREATE TABLE {$run_grants} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  competition_id bigint(20) unsigned NOT NULL,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tier_key varchar(32) NOT NULL DEFAULT '',
  qty int(10) unsigned NOT NULL DEFAULT 0,
  consumed int(10) unsigned NOT NULL DEFAULT 0,
  reserved_per_run int(10) unsigned NOT NULL DEFAULT 0,
  config_snapshot longtext DEFAULT NULL,
  status varchar(16) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY order_item (order_id, order_item_id),
  KEY balance_idx (user_id, competition_id, tier_key, status),
  KEY competition_id (competition_id)
) {$charset_collate};

CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  event varchar(32) NOT NULL DEFAULT '',
  level varchar(16) NOT NULL DEFAULT 'info',
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  competition_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tier_key varchar(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slot_no int(10) unsigned NOT NULL DEFAULT 0,
  message text NOT NULL,
  context longtext DEFAULT NULL,
  PRIMARY KEY (id),
  KEY created_at (created_at),
  KEY event (event),
  KEY level (level),
  KEY competition_id (competition_id),
  KEY run_id (run_id)
) {$charset_collate};
";

		dbDelta( $sql );

		update_option( self::OPTION_KEY, self::DB_VERSION );
	}
}
