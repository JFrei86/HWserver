from __future__ import print_function
from datetime import date
import os
import sys

import unittest2
from selenium import webdriver

if sys.version_info[0] == 3:
    raw_input = input


class BaseTestCase(unittest2.TestCase):
    """
    Base class that all e2e tests should extend. It provides several useful
    helper functions, sets up the selenium webdriver, and provides a common
    interface for logging in/out a user. Each test then only really needs to
    override user_id, user_name, and user_password as necessary for a
    particular testcase and this class will handle the rest to setup the test.
    """
    TEST_URL = "http://192.168.56.101"
    USER_ID = "student"
    USER_NAME = "Joe"
    USER_PASSWORD = "student"
    DRIVER = webdriver.Chrome()

    def __init__(self, *args, **kwargs):
        super(BaseTestCase, self).__init__(*args, **kwargs)
        if "TEST_URL" in os.environ and os.environ['TEST_URL'] is not None:
            self.test_url = os.environ['TEST_URL']
        else:
            self.test_url = BaseTestCase.TEST_URL
        self.user_id = BaseTestCase.USER_ID
        self.user_name = BaseTestCase.USER_NAME
        self.user_password = BaseTestCase.USER_PASSWORD
        self.semester = BaseTestCase.get_current_semester()
        self.logged_in = False

    def setUp(self):
        self.driver = BaseTestCase.DRIVER
        self.log_in()

    def tearDown(self):
        self.log_out()

    def get(self, url):
        if url[0] != "/":
            url = "/" + url
        self.driver.get(self.test_url + url)

    def log_in(self):
        """
        Provides a common function for logging into the site (and ensuring
        that we're logged in)
        :return:
        """
        self.get("/index.php?semester=" + self.semester + "&course=csci1000")
        assert "CSCI1000" in self.driver.title
        self.driver.find_element_by_name('user_id').send_keys(self.user_id)
        self.driver.find_element_by_name('password').send_keys(self.user_password)
        self.driver.find_element_by_name('login').click()
        assert self.user_name == self.driver.find_element_by_id("login-id").text
        self.logged_in = True

    def log_out(self):
        if self.logged_in:
            self.logged_in = False
            self.driver.find_element_by_id('logout').click()
            self.driver.find_element_by_id('login-guest')

    @staticmethod
    def wait_user_input():
        """
        Causes the running selenium test to pause until the user has hit the enter key in the
        terminal that is running python. This is useful for using in the middle of building tests
        as then you cna use the javascript console to inspect the page, get the name/id of elements
        or other such actions and then use that to continue building the test
        """
        raw_input("Hit enter to continue...")

    @staticmethod
    def get_current_semester():
        """
        Returns the "current" academic semester which is in use on the Vagrant/Travis machine (as we
        want to keep referring to a url that is "up-to-date"). The semester will either be spring
        (prefix "s") if we're in the first half of the year otherwise fall (prefix "f") followed
        by the last two digits of the current year. Unless you know you're using a course that
        was specifically set-up for a certain semester, you should always be using the value
        generated by this function in the code.

        :return:
        """
        today = date.today()
        semester = "f" + str(today.year)[-2:]
        if today.month < 7:
            semester = "s" + str(today.year)[-2:]
        return semester

#if __name__ == "__main__":
#    unittest2.main()
