# iThenticate Plagiarism Detector Plugin

For OJS/OMP/OPS 3.x

## Overview

This plugin permits automatic submission of uploaded manuscripts to the iThenticate service for plagiarism checking.
1. You need an account of ithenticate.com (costs involved)
  * paid via Crossref Similarity Check
  * or, paid directly to iThenticate
2. Install the plugin via the Plugin Gallery in the Dashboard
3. Configure the plugin (see below)
  * Enable the plugin via config.inc.php or in a specific journal/press/preprint context
  * Configure the plugin with the username and password you get from ithenticate.com
4. The author logs in and makes a submission
  * The submission files will be sent to iThenticate in Step 4 of the submission process
5. The Editor logs in to ithenticate.com to see the submission
  * The submission will be found in a folder named by the Submission ID

## Configuration

You may set the credentials in config.inc.php, or you may set the credentials per-journal in the plugin settings.  If credentials are present in config.inc.php, they will override those entered in the plugin settings form.

The config.inc.php settings format is:

```
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;
; iThenticate Plugin Settings ;
;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;;

[ithenticate]

; Enable iThenticate to submit manuscripts after submit step 4
;ithenticate = On

; Credentials can be set by context : specify journal path
; The username to access the API (usually an email address)
;username[MyJournal_path] = "user@email.com"
; The password to access the API
;password[MyJournal_path] = "password"

; default credentials
; The username to access the API (usually an email address)
;username = "user@email.com"

; The password to access the API
;password = "password"
```

