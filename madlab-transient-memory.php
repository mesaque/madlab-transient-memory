<?php
/**
 * @package Mad Lab Brazil Transient Enhancement
 */
/*
 * Plugin Name: MadLab Brazil Transient Enhancement
 * Plugin URI:  https://madlabbrazil.com/
 * Description: This plugin is intended to overwrite the standard transient used in WordPress with more power.
 * Version:     2.0.0
 * Author:      Mesaque Silva
 * Author URI:  http://mesaquesoares.blogspot.com.br/
 * License:     GPLv2 or later
 * Text Domain: MadLabTransientMemory
 */

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

Copyright 2015-2016 Mad Laboratories Brazil.
*/

if ( ! function_exists( 'add_action' ) )
	exit;

class MadLabBrazil_Transient
{
	protected static $instance = null;

	/**
	 * PHP Extension are not installed
	 * http://php.net/manual/pt_BR/book.memcache.php
	 */
	protected $memcache = null;

	protected $query = null;

	protected $expiration_time;

	const PATTERN = "#\(|\)|'|\,|\=|\.| |\*#";

	//Memcache
	const PORT = 11211;
	const HOST = '127.0.0.1';

	/**
	 * Return an instance of this class.
	 *
	 * @since 1.0
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
	{
		if ( null === self::$instance )
			self::$instance = new self;

		return self::$instance;
	}

	public function __construct()
	{
		$this->set_memcache();
	}

	protected function set_memcache()
	{
		if ( function_exists( 'memcache_connect' ) )
			$this->memcache = $this->memcache_resource();
	}

	protected function memcache_resource()
	{
		return @memcache_connect( self::HOST, self::PORT );
	}

	public function set_query( $query )
	{
		$this->query = $query;
	}

	public function set_expiration_time( $expiration_time )
	{
		$this->expiration_time = intval( $expiration_time );
	}

	protected function get_transient()
	{
		global $wpdb;

		$query = $this->query;

		if ( ! is_string( $query ) )
			$query = json_encode( $query );

		$identification = $this->get_identification( $query );
		$date           = current_time( 'timestamp' );
		$sql_prepare    = $wpdb->prepare(
			"SELECT `value`
			 FROM `{$wpdb->prefix}madlabbrazil_transient`
			 WHERE `id`    = %d
			 	AND `date` > %d
			",
			$identification,
			$date
		);
		$result = $wpdb->get_var( $sql_prepare );

		if ( null === $result )
			return $this->set_get_result( $wpdb, $identification );

		return unserialize( $result );
	}

	protected function set_get_result( $wpdb, $identification )
	{
		$this->set_transient();

		$query = $wpdb->prepare(
			"SELECT `value`
			 FROM `{$wpdb->prefix}madlabbrazil_transient`
			 WHERE `id` = %d
			",
			$identification
		);
		$result = $wpdb->get_var( $query );

		return unserialize( $result );
	}

	protected function set_transient()
	{
		global $wpdb;

		$query  = $this->query;
		$string = true;

		if ( ! is_string( $query ) ):
			$string = false;
			$query = json_encode( $query );
		endif;

		$identification = $this->get_identification( $query );
		$results        = ( $string ) ? $wpdb->get_results( $this->query ) : new WP_Query( $this->query );

		//Delete some old register
		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );
		$wpdb->insert(
			"{$wpdb->prefix}madlabbrazil_transient",
			array(
				'id'    => $identification,
				'value' => serialize( $results ),
				'date'  => ( current_time( 'timestamp' ) + $this->expiration_time ),
			),
			array(
			 	'%d',
			 	'%s',
			 	'%d',
			)
		);
	}

	protected function get_transient_memcache()
	{
		$query = $this->query;

		//fallback on normal dba cache
		if ( null === $this->memcache )
			return $this->get_transient();

		if ( ! is_string( $query ) )
			$query = json_encode( $query );

		$identification = $this->get_identification( $query );
		$result         = $this->memcache->get( $identification );

		if ( false === $result ) :
			$this->set_transient_memcache();
			return $this->get_transient_memcache();
		endif;

		return unserialize( $result );
	}

	protected function set_transient_memcache()
	{
		global $wpdb;

		//fallback on normal dba cache
		if ( null === $this->memcache )
			return $this->set_transient();

		$query  = $this->query;
		$string = true;

		if ( ! is_string( $query ) ) :
			$string = false;
			$query = json_encode( $query );
		endif;

		$identification = $this->get_identification( $query );
		$this->memcache->delete( $identification );
		$results        = ( $string ) ? $wpdb->get_results( $this->query ) : new WP_Query( $this->query );

		$this->memcache->set( $identification, serialize( $results ), 0, $this->expiration_time  );
	}

	protected function delete_transient()
	{
		global $wpdb;

		$query = $this->query;

		if ( ! is_string( $query ) )
			$query = json_encode( $query );

		$identification = $this->get_identification( $query );
		$wpdb->delete( "{$wpdb->prefix}madlabbrazil_transient", array( 'id' => $identification ) );
	}

	protected function delete_transient_memcache()
	{
		global $wpdb;

		$query = $this->query;

		if ( ! is_string( $query ) )
			$query = json_encode( $query );

		$identification = $this->get_identification( $query );
		$this->memcache->delete( $identification );
	}

	protected function get_identification( $query )
	{
		$filtered       = preg_replace( self::PATTERN, '', $query );
		$checksum       = crc32( $filtered );
		$identification = sprintf( '%u', $checksum );

		return intval( $identification );
	}

	public function handle_get_transient()
	{
		if ( null !== $this->memcache )
			return $this->get_transient_memcache();

		return $this->get_transient();
	}

	public function handle_set_transient()
	{
		if ( null !== $this->memcache ) :
			$this->set_transient_memcache();
			return;
		endif;

		$this->set_transient();
	}

	public static function create_table()
	{
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$queries = "
			CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}madlabbrazil_transient` (
				`id`    INT(11)  UNSIGNED NOT NULL,
				`value` TEXT 		      NOT NULL,
				`date`  INT(20)   	      NOT NULL,
				PRIMARY KEY (`id`)
			) {$charset} ENGINE = MyISAM;
		";

		require_once ABSPATH . '/wp-admin/includes/upgrade.php';

		dbDelta( $queries );
	}

	public static function delete_table()
	{
		global $wpdb;

		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}madlabbrazil_transient`" );
	}
}

register_activation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'create_table' ) );
register_deactivation_hook( __FILE__, array( 'MadLabBrazil_Transient', 'delete_table' ) );

function madLab_brazil_get_transient( $query, $expiration_time = 7200 )
{
	$instance = MadLabBrazil_Transient::get_instance();
	$instance->set_query( $query );
	$instance->set_expiration_time( $expiration_time );

	return $instance->handle_get_transient();
}

function madLab_brazil_set_transient( $query, $expiration_time = 7200 )
{
	$instance = MadLabBrazil_Transient::get_instance();

	$instance->set_query( $query );
	$instance->set_expiration_time( $expiration_time );
	$instance->handle_set_transient();
}