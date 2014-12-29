Github to WordPress Plugins Sync
================================

Scripts for syncing from Github to WordPress.org Plugins SVN

This acts as a webhook for Github allowing WordPress plugins which live on Github to be sync'd to plugins.svn.wordpress.org.

The script will sync to HEAD on each GH commit (or merge), an attempt to sync the commit messages to plugins.svn.wordpress.org.

This script has been written for a narrow use-case, but should be extendible (or suffice) for most other use-cases.
The use-case this has been written for is:
 * All changes must be sync'd
 * /assets/ in Github must be removed from all builds (and master:/assets/ is moved to /assets/ in the SVN sync)
 * Branches/Tags will be copied over
 * No deletions will be sync'd, this must be done manually in SVN
 * It's best to set a new user account, and give that commit priv for the plugin being sync'd
 * README.MD will NOT be converted to readme.txt automatically
 * All commits will show as being by the SVN sync user (but the commit message will detail the Github author/committer)
 * This is best used for *self-contained* Plugins, Git submodules are ignored (as it internally uses SVN)
 * Certain "tokens" will be replaced in the SVN copy, allowing for dynamic builds, for example:
   * "%GITHUB_MERGE_SVN_REV%" will be replaced with the SVN version of the Github repo (Every commit will increment it, so it's a good numeric timeline identifier)
   * "%GITHUB_MERGE_DATE%" will be replaced with the current date upon which the sync happens, for example, 2014-12-25
   * "%GITHUB_MERGE_DATETIME%" will be replaced with the current datetime upon which the sync happens, for example, "2014-12-25 17:00:00"
 * For WordPress feature plugins which use this, it'd be advised to include "%GITHUB_MERGE_SVN_REV%" in the Plugin Version header, and keep the plugin development in trunk (Stable Tag: trunk) to allow for nightly builds of the plugin to be sent out. You could also incorporate "%GITHUB_MERGE_DATE%" in there if wished.
 * To perform a Release, one would have to make the release from a revision which has the "Stable Tag" set to what the release will be, in this case, it's best not to use trunk and instead release from a branch, and then update the readme.TXT in trunk to reflect it.
