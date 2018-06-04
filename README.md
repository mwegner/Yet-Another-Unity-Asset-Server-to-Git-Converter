
# Yet Another Unity Asset Server to Git Converter
PHP script to convert a Unity Asset Server project into a Git repository.

I'm aware of two existing conversion scripts:

* Unity Technologies' own [git-unity-as](https://github.com/Unity-Technologies/git-unity-as) (Python).
* Toru Nayuki's [uas2git](https://github.com/tnayuki/uas2git) (Ruby)

So why write another one?  The short answer:  There are bugs in the above scripts.  I am not at all familiar with Python or Ruby for addressing architectural/logic bugs, and I have a bunch of Asset Server PHP code around already, so it was just faster to roll my own (I wrote [this Asset Server browser](https://github.com/dotBunny/UASB) in an afternoon, years ago).

Our game Aztez is _old_.  Like, started-in-early-Unity 3.x-days old.  So rather than fight with constant importer bugs, and then find a problem years from now when looking through the history of something, I wanted to make sure it was archived correctly.

## Issues With Existing Scripts

For posterity, problems with the two above scripts:

* The Ruby version is especially old, and simply breaks out of the box with modern versions of Rugged and ActiveRecord.  To run as-is, you want rugged 0.21.4 and activerecord 4.1.9.  I have no idea why package managers like Gem don't have a "--contemporary" flag to retain better compatibility with old code.
* _Very_ old Unity Asset Server projects have different tags for meta files, which neither script addresses.  This is a fast fix, though.
* But more problematic is that both scripts attempt to track renames of files.  The Asset Server has implicit renames.  Here's one example that breaks Unity's importer:  "AssetA" is deleted, and a totally new file appears with the same path/name as "AssetA".  Because they are tracking renames, but not sorting the order of operations correctly, the git-fast-import stream gets the modification first, and the delete second.  Now the file is deleted, and future renames/modifications to it will fail.  You have to correctly delete trees in a deep->shallow manner too.

## Delete/Reset Method

This script follows the advice in the git-fast-import documentation on handling renames:  [Don't](https://git-scm.com/docs/git-fast-import#_handling_renames).  I completely scrub the index every commit and then just rattle off all non-deleted files in the project.  Git takes care of it; it's fine.

Files are only read once and marked as blobs, so performance isn't that much worse than a tracking system.  It takes ~10 minutes for a small 500-commit project here, and about 3 hours for a larger 4000-commit project.

I'll gladly trade accuracy and archival peace of mind over speed for a one-time process.

## Usage

I'm on macOS, which seems to include the correct PHP extensions for PostgreSQL out of the box.  The script is meant to be used as a command line:

Download repository as ZIP (probably don't want to clone, since you'll end up with a git repository inside a git repository).

The script injects the .gitignore/.gitattributes files into the repository history.  Edit if needed, or simply delete.

Then, from within the repository root:

    mkdir someproject
    git init
    php ../import.php hostname user password project | git fast-import

This should give you a repository with the full .git database.  To populate with the latest commit:

    git  reset  --hard  HEAD

To add a remote that supports lfs, import files to lfs, and push:

    git  remote  add  origin  https://your_repo_url_here
    git lfs install
    git  lfs  migrate  import  --include="*.jpg, *.jpeg, *.png, *.gif, *.psd, *.ai, *.mp3, *.wav, *.ogg, *.flac, *.mp4, *.mov, *.FBX, *.fbx, *.blend, *.obj, *.a, *.exr, *.tga, *.pdf, *.zip, *.dll, *.unitypackage, *.aif, *.ttf, *.rns, *.reason, *.lxo, *.mb"
    git  push  --force  --set-upstream  origin  master

(You probably don't need the --force, but I'm stomping a repository here).

## Help?

I fully expect zero people to need this script.  But hey, it's here if you do.  If you are in a situation where you have old Asset Server projects that you need to archive on modern infrastructure, this should do it.

I probably don't have time to help, but you can [email me](mailto:matthew@teamcolorblind.com) anyway.  If you're _really_ in a jam, I do infrastructure type consulting work these days!
