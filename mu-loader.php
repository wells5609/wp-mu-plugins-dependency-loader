<?php
/**
* Plugin Name: Must-Use Loader
* Description: Load Must-Use Plugins in subdirectories using dependencies.
* Version:     0.0.4
* Author:      wells
*/

if ( !defined('ABSPATH') ) exit;

add_action( 'muplugins_loaded',	array( Mu_Plugins_Loader::instance(), 'init' ), 0 );

/**
* Class Mu_Plugins_Loader
*/
class Mu_Plugins_Loader {

	static protected $_instance;
	
	/**
	 * Handler for the action 'init'. Instantiates this class.
	 * @since  0.0.1
	 * @return Mu_Plugins_Loader
	 */
	public static function instance(){
		if ( !isset(self::$_instance) )
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	* Init
	*/
	public function init() {
		
		$this->_plugins = new Plugin_Dependency_Loader( $this->getPlugins() );
		
		// Delete transient cache, if active on the must use plugin list in network view
		add_action( 'load-plugins.php', array($this, 'delete_cache') );

		// Add row and content for all plugins, there include via this plugin
		add_action( 'after_plugin_row_mu-loader.php', array($this, 'view_plugins') );
	}

	/**
	* Get all plugins in subdirectories. Write in a transient cache.
	*/
	protected function getPlugins() {
		
		// Deactivate caching ?
		if ( defined('MU_PLUGINS_NOCACHE') && MU_PLUGINS_NOCACHE ){
			$plugins = false;
		} else {
			$plugins = get_site_transient( 'subdir_wpmu_plugins' );
		}
		
		if ( false === $plugins ){
			
			$plugins = array();
			$files = get_plugin_files_in_subdirectories( WPMU_PLUGIN_DIR );
			
			foreach( $files as $file ){
				$plugins[ $file ] = get_plugin_with_dependencies_data( $file );
			}
			
			set_site_transient( 'subdir_wpmu_plugins', $plugins );
			
		} else {
		
			foreach( $plugins as $file => $data ){
				// Validate plugins still exist
				if ( ! is_readable( $file ) ){
					$plugins = false;
					break;
				}
			}
		}
		
		return $plugins;
	}

	/**
	* Delete the transient cache if on the MU Plugins page in wp-admin.
	*/
	function delete_cache() {
		if ( 'plugins' === get_current_screen()->id && isset($_SERVER['QUERY_STRING']) && 'plugin_status=mustuse' === $_SERVER['QUERY_STRING'] ){
			delete_site_transient( 'subdir_wpmu_plugins' );
		}
	}

	/**
	* Add row for each plugin under this plugin on the MU Plugins page in wp-admin.
	*/
	function view_plugins() {
		
		foreach($this->getPlugins() as $file => $data){
			
			$order = array_search( $data['id'], $this->_plugins->load_order );
			
			$ordered[ $order ] = $data;
		}
		
		ksort( $ordered );
		
		foreach( $ordered as $order => $data ){
			
			$name        = empty( $data['Name'] ) ? '?' : $data['Name'];
			$desc        = empty( $data['Description'] ) ? '&nbsp;' : $data['Description'];
			$version     = empty( $data['Version'] ) ? '' : $data['Version'];
			$author_name = empty( $data['AuthorName'] ) ? '' : $data['AuthorName'];
			$author      = empty( $data['Author'] ) ? $author_name : $data['Author'];
			$author_uri  = empty( $data['AuthorURI'] ) ? '' : $data['AuthorURI'];
			$plugin_site = empty( $data['PluginURI'] ) ? '' : '| <a href="' . $data['PluginURI'] . '">' . __( 'Visit plugin site' ) . '</a>';
			$id          = sanitize_title( $file );
			
			$deps		 = empty( $data['Depends'] ) ? '' : ' | <span><b>Depends on: </b>' . implode(', ', $data['Depends']) . '</span>';
			$provides	 = empty( $data['Provides'] ) ? '' : ' | <span><b>Provides: </b>' . implode(', ', $data['Provides']) . '</span>';

			?>
			<tr id="<?php echo $id; ?>" class="active">
				<th scope="row" class="check-column"></th>
				<td class="plugin-title">
					<strong title="<?php echo $id; ?>"><?php echo $name; ?></strong>
					<em>Load order: <?php echo $order; ?></em>
				</td>
				<td class="column-description desc">
					<div class="plugin-description"><p><?php echo $desc; ?></p></div>
					<div class="active second plugin-version-author-uri">
						<?php printf( esc_attr__( 'Version %s | By %s %s' ), $version, $author, $plugin_site ) ; ?>
						<?php echo $deps; ?>
						<?php echo $provides; ?>
					</div>
				</td>
			</tr>
			<?php
		}
	}

}

class Plugin_Dependency_Loader {
	
	public $plugins = array();
	
	public $plugin_data = array();
	
	public $providers = array();
	
	public $provided = array();
	
	public $loaded = array();
	
	public $load_as_available = true;
	
	public $load_order = array();
	
	
	function __construct( array $plugins ){
		
		$this->scanPlugins( $plugins );
		
		$this->loadAll();
	}
	
	protected function scanPlugins( array $plugins ){
		
		static $c = 1;
		
		foreach( $plugins as $file => $data ){
			
			$id = $data['id'];
			
			$this->plugins[ $id ] = $file;
			$this->plugin_data[ $id ] = $data;
			
			// Providers can also have dependencies, so we 
			// load the dependencies provided first.
			
			// providers
			if ( null !== $data['Provides'] ){
				foreach( $data['Provides'] as $p ){
					$this->add_provider( $p, $id );	
				}
			}
			
			// dependencies
			if ( null !== $data['Depends'] ){
				foreach( $data['Depends'] as $d ){
					if ( ! $this->is_provided( $d ) )
						continue 2; // don't load
				}
			}
			
			if ( $this->load_as_available ){
				
				require_once $file;
				
				$this->load_order[ $c ] = $id;
				
				$this->set_loaded( $id );
				
				if ( null !== $data['Provides'] ){
					foreach( $data['Provides'] as $dep ){
						$this->set_provided( $dep, $id );
					}
				}
			
			} else {
				$this->load_order[ $c ] = $id;
			}
				
			$c++;
		
		}
	}
	
	protected function loadAll(){
		
		$c = max( array_keys( $this->load_order ) ) + 1;
		
		foreach( $this->plugins as $id => $file ){
			
			$this->loadPlugin( $id, $c );
		}
	}
	
	protected function loadPlugin( $id, $c = 1 ){
		
		if ( in_array( $id, $this->loaded ) ){
			#var_dump( $c, 'LOADED - Skipping', $id, $this->get_dependencies($id), $this->loaded, $this->provided );
			return;
		}
		
		$_load = true;
				
		foreach( $this->get_dependencies( $id ) as $dep ){
			
			if ( ! $this->is_provided( $dep ) ){
				
				$provider = $this->find_provider( $dep );
				
				if ( !$provider || !$this->is_loaded( $provider ) )
					$_load = false;
			}
		}
		
		#$will_load = $_load ? 'Dependencies satisfied - Loading...' : 'Dependencies not satisfied - NOT loading.';
		#var_dump( $c, $will_load, $id, $this->get_dependencies($id), $this->loaded, $this->provided );
			
		if ( $_load ){
			
			require_once $this->plugins[ $id ];
			
			$this->set_loaded( $id );
			
			$this->load_order[ $c ] = $id;
			
			$c++;
		}
	}
	
	public function get_dependencies( $id ){
		return isset( $this->plugin_data[ $id ] ) ? $this->plugin_data[ $id ]['Depends'] : null;	
	}
	
	public function set_loaded( $id ){
		$this->loaded[] = $id;
		return $this;
	}
	
	public function is_loaded( $id ){
		return in_array( $id, $this->loaded );	
	}
	
	public function set_provided( $dependency, $id ){
		$this->provided[ $dependency ] = $id;
		return $this;	
	}
	
	public function is_provided( $dependency ){
		return isset( $this->provided[ $dependency ] );	
	}
	
	public function add_provider( $dependency, $id ){
			
		if ( ! isset( $this->providers[ $dependency ] ) )
			$this->providers[ $dependency ] = array();
		
		$this->providers[ $dependency ][] = $id;
		
		return $this;
	}
	
	public function find_provider( $dependency ){
		
		if ( ! isset( $this->providers[ $dependency ] ) )
			return false;
		
		$providers = $this->providers[ $dependency ];
		
		if ( count($providers) > 1 ){
			foreach( $providers as $id ){
				//  try to return a loaded provider first
				if ( $this->is_loaded( $id ) )
					return $id;	
			}
		}
		
		return array_shift( $providers ); // return the 1st
	}
			
}

/**
* Returns array of files that match the subdirectory names in the given directory.
* Just how plug-ins are loaded, except does NOT include base dir-level files (i.e. without subdir).
*/
function get_plugin_files_in_subdirectories( $directory ){
	
	$files = array();
	// remove '..' and '.' paths
	$items = array_diff( scandir( $directory ), array('..', '.') );
	
	if ( empty($items) ) 
		return $files;
	
	foreach( $items as $item ){
		
		if ( pathinfo( $item, PATHINFO_EXTENSION ) )
			continue; // skip files
	
		$name = basename( $item ); // get plugin file name from dir name
		
		$file = $directory . '/' . $item . '/' . $name . '.php';

		if ( file_exists( $file ) ){
			$files[ $name ] = $file;	
		}	
	}
	
	return $files;	
}

function get_plugin_with_dependencies_data( $file ){
	
	$headers = array(
		'Name' => 'Plugin Name',
		'PluginURI' => 'Plugin URI',
		'Version' => 'Version',
		'Description' => 'Description',
		'Author' => 'Author',
		'AuthorURI' => 'Author URI',
		'TextDomain' => 'Text Domain',
		'DomainPath' => 'Domain Path',
		'Network' => 'Network',
		'Depends' => 'Depends',
		'Provides' => 'Provides',
	);
	
	$data = get_file_data( $file, $headers, 'plugin_deps' );
	
	$data['Title']      = $data['Name'];
	$data['AuthorName'] = $data['Author'];
	
	$data['Depends']	= empty($data['Depends']) ? NULL : array_map( 'trim', explode(',', $data['Depends']) );
	$data['Provides']	= empty($data['Provides']) ? NULL : array_map( 'trim', explode(',', $data['Provides']) );
	
	$data['id']			= str_replace( array(' ','.', ',','/','-'), '_', strtolower($data['Name']) );
	
	return $data;
}