<?php
/**
* Plugin Name: Must-Use Loader
* Description: Load Must-Use Plugins in subdirectories using dependencies.
* Version:     0.0.6
* Author:      wells
*/

if ( !defined('ABSPATH') ) exit;

add_action( 'muplugins_loaded',	array( Mu_Plugins_Loader::instance(), 'init' ), 0 );

function is_plugin_dependency_provided( $dependency ){
	$mu = Mu_Plugins_Loader::instance();
	return $mu->_loader->is_provided( $dependency );
}

function get_plugin_dependency_provider( $dependency ){
	$mu = Mu_Plugins_Loader::instance();
	return $mu->_loader->get_provider( $dependency );
}

/**
* Class Mu_Plugins_Loader
*/
class Mu_Plugins_Loader {

	static protected $_instance;
	
	/**
	* Instantiates this class.
	*/
	public static function instance(){
		if ( !isset(self::$_instance) )
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	* Initializer. Loads plug-ins.
	*/
	public function init() {
		
		$this->_loader = new DependencyLoader( WPMU_PLUGIN_DIR );
		
		$plugins = $this->getPlugins();
		
		ksort( $plugins );
		
		foreach( $plugins as $i => $file ){
			require $file;	
		}
		
		// Delete transient cache, if active on the must use plugin list in network view
		add_action( 'load-plugins.php', array($this, 'delete_cache') );

		// Add row and content for all plugins, there include via this plugin
		add_action( 'after_plugin_row_mu-loader.php', array($this, 'view_plugins') );
	}

	/**
	* Get all plugins in subdirectories. Write in a transient cache.
	*/
	protected function getPlugins() {
		
		if ( defined('NOCACHE_MU_PLUGINS') && NOCACHE_MU_PLUGINS ){
			// Deactivate caching? Really only useful for debugging
			$plugins = false;
		} else {
			$plugins = get_site_transient( 'subdir_wpmu_plugins' );
		}
		
		if ( false !== $plugins ){
			
			foreach( $plugins as $i => $file ){
				// Validate plugins still exist
				if ( ! is_readable( $file ) ){
					$plugins = false;
					break;
				}
			}	
		}
		
		// Invalid cache? Get ordered file array from DependencyLoader
		if ( false === $plugins ){
			
			$this->_loader->init();
			
			$plugins = $this->_loader->get_plugins();
			
			set_site_transient( 'subdir_wpmu_plugins', $plugins );
		}
		
		return $plugins;
	}
	
	function get_plugins_data( $plugin_id = null ){
		
		if ( !empty($plugin_id) ){
		
			if ( !isset($this->_loader->plugins_data[ $plugin_id ]) )
				return null;
		
			return $this->_loader->plugins_data[ $plugin_id ];	
		}
		
		return $this->_loader->plugins_data;	
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
		
		$ordered = array();
		$plugins = $this->getPlugins();
		ksort( $plugins );
		
		foreach($plugins as $i => $file){
			$ordered[ $i ] = $this->_loader->get_plugin_data_from_file( $file );
		}
			
		if ( !empty($this->_loader->unsatisfiable) ){
			
			$unsatisfied = array();
			
			foreach( $this->_loader->unsatisfiable as $id ){
				$unsatisfied[] = $this->_loader->plugin_data[ $id ];	
			}
			
			$ordered = array_merge( $ordered, $unsatisfied );	
		}
		
		foreach( $ordered as $order => $data ){
			
			$data = (array) $data;
			
			$name        = empty( $data['Name'] ) ? '?' : $data['Name'];
			$desc        = empty( $data['Description'] ) ? '&nbsp;' : $data['Description'];
			$version     = empty( $data['Version'] ) ? '' : $data['Version'];
			$author_name = empty( $data['AuthorName'] ) ? '' : $data['AuthorName'];
			$author      = empty( $data['Author'] ) ? $author_name : $data['Author'];
			$author_uri  = empty( $data['AuthorURI'] ) ? '' : $data['AuthorURI'];
			$plugin_site = empty( $data['PluginURI'] ) ? '' : '| <a href="' . $data['PluginURI'] . '">' . __( 'Visit plugin site' ) . '</a>';
			$id          = sanitize_title( $file );
			
			$provides	 = empty( $data['Provides'] ) ? '' : ' | <em><b>Provides: </b></em>' . implode(', ', $data['Provides']);
			
			$dep_strs = array();
			
			if ( !empty($data['Depends']) ){				
				foreach( $data['Depends'] as $dep ){
					$provided = is_plugin_dependency_provided( $dep );
					$dep_strs[] = $provided ? '<span style="color:green" title="Provided by: ' . get_plugin_dependency_provider( $dep ) . '">' . $dep . '</span>' : '<span style="color:red">' . $dep . '</span>';
				}
			}
			
			$deps = empty($dep_strs) ? '' : ' | <b><em>Dependencies: </em></b>' . implode(', ', $dep_strs);
			
			$active = in_array( sanitize_title_with_dashes( $data['Name'] ), $this->_loader->unsatisfiable ) ? 'inactive' : 'active';
			
			$order = 'inactive' === $active ? 'Unsatisfied dependencies' : 'Load order: ' . $order;
			
			?>
			<tr id="<?php echo $id; ?>" class="<?php echo $active; ?>">
				<th scope="row" class="check-column"></th>
				<td class="plugin-title">
					<strong title="<?php echo $id; ?>"><?php echo $name; ?></strong>
					<em style="color:#888"><?php echo $order; ?></em>
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

class DependencyLoader {
	
	public $plugins = array();
	
	public $plugin_data = array();
	
	public $providers = array();
	
	public $waiting = array();
	
	public $unsatisfiable = array();
	
	public $provided = array();
	
	static public $queue = array();
	
	static protected $scanned = false;
	
	protected $path;
		
	function __construct( $dirpath ){
		
		$this->path = $dirpath;
	}
	
	/**
	* Scan files, start queue, and loop through $waiting array until empty.
	* 
	* When all of a plugin's dependencies are satisfied, the plugin is
	* added to the queue and removed from the $waiting array.
	*/
	public function init(){
		
		$this->scan_plugins();	
				
		$this->startQueue();
		
		do { 
			$this->resolveWaiting();
		} while ( ! empty( $this->waiting ) );
		
	}
	
	/**
	* Returns queued plugin file array.
	*/
	public function get_plugins(){
		
		ksort( self::$queue );
		
		$files = array();
		
		foreach( self::$queue as $i => $id ){
			
			$files[ $i ] = $this->plugins[ $id ]; // return the filepath	
		}
		
		return $files;
	}
	
	/**
	* Returns a plugin's data given its file path
	*/
	public function get_plugin_data_from_file( $file ){
		return $this->plugin_data[ $this->get_plugin_id_from_file( $file ) ];	
	}
	
	/**
	* Returns a plugin's id given its file path.
	*/
	public function get_plugin_id_from_file( $file ){
		return array_search( $file, $this->plugins );	
	}
	
	/**
	* Returns indexed array of plugin dependencies, if any.
	*/
	public function get_dependencies( $id ){
		return isset( $this->plugin_data[ $id ] ) ? $this->plugin_data[ $id ]->Depends : null;	
	}
		
	/**
	* Returns indexed array of dependencies provided by the plugin, if any.
	*/
	public function get_provides( $id ){
		return isset( $this->plugin_data[ $id ] ) ? $this->plugin_data[ $id ]->Provides : null;	
	}
	
	/**
	* Returns true if plugin is in queue, otherwise returns false.
	*/
	public function is_queued( $id ){
		return in_array( $id, self::$queue );	
	}
	
	/**
	* Returns true if dependency has been provided (i.e. a provider is in the queue)
	* Otherwise returns false.
	*/
	public function is_provided( $dependency ){
		return isset( $this->provided[ $dependency ] );	
	}
	
	/**
	* Returns true if dependency has a known provider.
	*/
	public function has_provider( $dependency ){
		return isset( $this->providers[ $dependency ] );	
	}
	
	/**
	* Adds a plugin as a dependency provider.
	* Dependencies can have multiple providers.
	*/
	public function add_provider( $dependency, $id ){
			
		if ( ! isset( $this->providers[ $dependency ] ) )
			$this->providers[ $dependency ] = array();
		
		$this->providers[ $dependency ][] = $id;
		
		return $this;
	}
	
	/**
	* Returns the id of a plugin that provides the given dependency.
	* First tries to return a plugin already queued.
	*/
	public function get_provider( $dependency ){
		
		if ( $this->is_provided( $dependency ) )
			return $this->provided[ $dependency ];
		
		if ( ! $this->has_provider($dependency) )
			return false;
		
		$providers = $this->providers[ $dependency ];
		
		if ( count($providers) > 1 ){
			foreach( $providers as $id ){
				//  try to return a queued provider first
				if ( in_array( $id, self::$queue ) )
					return $id;	
			}
		}
		
		return reset( $providers ); // return the 1st
	}
	
	// for debugging/viewing the load queue
	public function dump_queue(){
		print_r( self::$queue );	
	}
			
	/**
	* Scan the plugins to find dependency providers - this means later we
	* will know whether plugins with those dependencies can be loaded.
	*/
	public function scan_plugins(){
				
		$plugins = get_plugin_files_in_subdirectories( $this->path );
		
		foreach( $plugins as $file ){
			
			$data = (object) get_plugin_with_dependencies_data( $file );
			
			$this->plugins[ $data->id ] = $file;
			$this->plugin_data[ $data->id ] = $data;
			
			// Add as provider of each dep 
			if ( !empty( $data->Provides ) ){
				foreach( $data->Provides as $dep ){
					$this->add_provider( $dep, $data->id );	
				}
			}
		}
	}
	
	protected function startQueue(){
		
		foreach( $this->plugin_data as $id => $data ){
			
			if ( !empty( $data->Depends ) ){
				
				// plugin has deps - loop through
				foreach( $data->Depends as $dep ){
					
					// If dependency is not provided, see if a provider is available.
					// If not, this plugin is "unsatisfiable" and we will not try to load it. 
					// Otherwise, it is put into the "waiting" array to be loaded later.
					if ( ! $this->is_provided( $dep ) ){
						
						if ( ! $this->has_provider( $dep ) ){
							$this->unsatisfiable[] = $id;
						} else {
							$this->waiting[] = $id;	
						}
						
						continue 2;
					}
				}
			}
			// If we've reached this point, all dependencies (if any) are provided.
			$this->addToQueue( $id );
		}
	}
	
	/**
	* Loop through waiting plugins and add to queue if dependencies satisfied.
	*
	* Each time, check if dependencies have been satisified yet (i.e. a provider
	* has been added to the queue). If so, add plugin to queue and remove from 
	* the $waiting array. Otherwise, keep looping through.
	*/
	protected function resolveWaiting(){
		
		foreach( $this->waiting as $id ){
			
			$deps = $this->get_dependencies( $id );
			
			if ( !empty( $deps ) ){
				foreach( $deps as $dep ){
					if ( ! $this->is_provided( $dep ) )
						continue 2;
				}
			}
		
			$this->addToQueue( $id );
			$this->resetWaiting();
		}
	}
	
	/**
	* Remove the most recently queued id from the $waiting array.
	*
	* Since this is called while looping through $waiting, we cannot use
	* unset() - or at least I couldn't get it to work.
	*/
	protected function resetWaiting(){
		
		$added = array_intersect( $this->waiting, self::$queue );
		
		$waiting = array_diff( $this->waiting, $added );
		
		$this->waiting = $waiting;
	}
		
	/**
	* Adds an id to the ordered loading queue.
	*
	* If plugin provides dependencies, we set those dependencies as provided.
	*/
	protected function addToQueue( $id ){
		static $order;
		if ( !isset($order) )
			$order = 1;
		
		if ( in_array( $id, self::$queue ) ) return; // already queued
		
		self::$queue[ $order ] = $id;
	
		$order += 1; // increment order
	
		if ( $provides = $this->get_provides( $id ) ){
			
			foreach( $provides as $dep ){
				$this->setProvided( $dep, $id );	
			}	
		}
		
	}
	
	/**
	* Set a dependency as provided by a plugin (id).
	*/
	protected function setProvided( $dependency, $id ){
		$this->provided[ $dependency ] = $id;
		return $this;	
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
		
		if ( ! is_dir( $directory . '/' . $item ) )
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
	
	$data['Depends']	= empty($data['Depends'])  ? NULL : array_map( 'trim', explode(',', $data['Depends']) );
	$data['Provides']	= empty($data['Provides']) ? NULL : array_map( 'trim', explode(',', $data['Provides']) );
	
	$data['id']			= sanitize_title_with_dashes( $data['Name'] );
	
	return $data;
}