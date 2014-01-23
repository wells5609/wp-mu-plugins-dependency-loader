wp-mu-plugins-dependency-loader
===============================

Loads MU plug-ins, which can have and provide dependencies.

###Overview:

How it works:

 1. Finds plug-in files in _subdirectories_ of `WPMU_PLUGIN_DIR`
 2. Scans file headers for `Provides: ` and `Depends: ` data
 3. Queues files for loading based on existance of dependency providers; ordered so that dependencies are provided first; discards plugins with dependencies that are not provided.
 4. Saves ordered file queue as transient (deleted & rescanned when visiting MU plug-ins page in wp-admin).
 5. Loads plug-in files in order
