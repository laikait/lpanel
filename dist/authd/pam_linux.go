//go:build linux && cgo

package main

/*
#cgo LDFLAGS: -lpam
#include <security/pam_appl.h>
#include <stdlib.h>
#include <string.h>

// Answers the password prompt with appdata, and nothing else: a module that
// asks a second question (a one-time code, a new password) makes the check fail.
static int lpanel_conv(int n, const struct pam_message **msg, struct pam_response **resp, void *appdata) {
	if (n <= 0 || n > PAM_MAX_NUM_MSG) {
		return PAM_CONV_ERR;
	}
	struct pam_response *r = calloc((size_t)n, sizeof(struct pam_response));
	if (r == NULL) {
		return PAM_BUF_ERR;
	}
	int answered = 0;
	for (int i = 0; i < n; i++) {
		int style = msg[i]->msg_style;
		if (style == PAM_PROMPT_ECHO_OFF && !answered) {
			r[i].resp = strdup((const char *)appdata);
			if (r[i].resp == NULL) {
				free(r);
				return PAM_BUF_ERR;
			}
			answered = 1;
		} else if (style == PAM_PROMPT_ECHO_OFF || style == PAM_PROMPT_ECHO_ON) {
			for (int j = 0; j < i; j++) {
				if (r[j].resp != NULL) {
					memset(r[j].resp, 0, strlen(r[j].resp));
					free(r[j].resp);
				}
			}
			free(r);
			return PAM_CONV_ERR;
		}
	}
	*resp = r;
	return PAM_SUCCESS;
}

static int lpanel_check(const char *service, const char *user, char *password, const char *rhost) {
	struct pam_conv conv = { lpanel_conv, password };
	pam_handle_t *h = NULL;
	int rc = pam_start(service, user, &conv, &h);
	if (rc != PAM_SUCCESS) {
		return rc;
	}
	rc = pam_set_item(h, PAM_RHOST, rhost);
	if (rc == PAM_SUCCESS) {
		rc = pam_authenticate(h, PAM_SILENT | PAM_DISALLOW_NULL_AUTHTOK);
	}
	if (rc == PAM_SUCCESS) {
		rc = pam_acct_mgmt(h, PAM_SILENT | PAM_DISALLOW_NULL_AUTHTOK);
	}
	pam_end(h, rc);
	return rc;
}
*/
import "C"

import (
	"fmt"
	"unsafe"
)

type pamVerifier struct {
	service string
}

func newPAMVerifier(service string) Verifier { return &pamVerifier{service: service} }

// Verify runs PAM's auth and account stacks. The C copy of the password is
// zeroed before it is freed. (Go strings are immutable, so the request's own
// copy is released, not zeroed; the process is single-purpose and root-only.)
func (p *pamVerifier) Verify(user, password, rhost string) error {
	cService, cUser, cHost := C.CString(p.service), C.CString(user), C.CString(rhost)
	defer C.free(unsafe.Pointer(cService))
	defer C.free(unsafe.Pointer(cUser))
	defer C.free(unsafe.Pointer(cHost))

	cPassword := C.CString(password)
	defer func() {
		C.memset(unsafe.Pointer(cPassword), 0, C.size_t(len(password)))
		C.free(unsafe.Pointer(cPassword))
	}()

	switch rc := C.lpanel_check(cService, cUser, cPassword, cHost); rc {
	case C.PAM_SUCCESS:
		return nil
	case C.PAM_AUTH_ERR, C.PAM_USER_UNKNOWN, C.PAM_ACCT_EXPIRED, C.PAM_PERM_DENIED,
		C.PAM_NEW_AUTHTOK_REQD, C.PAM_MAXTRIES, C.PAM_CRED_INSUFFICIENT, C.PAM_AUTHINFO_UNAVAIL, C.PAM_CONV_ERR:
		return ErrDenied
	default:
		return fmt.Errorf("%w: PAM returned %d", ErrUnavailable, int(rc))
	}
}
