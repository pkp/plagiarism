You may set the credentials in config.inc.php, or you may set the credentials per-journal in the plugin settings.  If credentials are present in config.inc.php, they will override those entered in the plugin settings form.

The config.inc.php settings format is:

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


