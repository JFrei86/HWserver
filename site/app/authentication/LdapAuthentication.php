<?php

declare(strict_types=1);

namespace app\authentication;

class LdapAuthentication extends AbstractAuthentication {
    public function authenticate() {
        if (empty($this->user_id) || empty($this->password) || $this->core->getQueries()->getSubmittyUser($this->user_id) === null) {
            return false;
        }

        $settings = $this->core->getConfig()->getAuthenticationSettings()['ldap_options'];

        $ldap = ldap_connect($settings['url']);
        ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);

        return @ldap_bind($ldap, "{$settings['uid']}={$this->username},{$settings['bind_dn']}", $this->password);
    }
}
