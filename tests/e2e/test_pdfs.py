from .base_testcase import BaseTestCase
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import Select
from selenium.webdriver.firefox.options import Options
from selenium.common.exceptions import NoSuchElementException        
import os
import unittest
import time
class TestPDFs(BaseTestCase):
    def __init__(self, testname):
        super().__init__(testname, log_in=False)
    @unittest.skipUnless(os.environ.get('CI') is None, "cannot run in CI")
    def test_pdf_basic_access(self):
        self.switch_settings("Limited Access Grader")
        self.pdf_access("instructor", "3", "8" ,"grading_homework_pdf", "words_1463.pdf", "2")
        self.pdf_access("ta", "3", "8", "grading_homework_pdf", "words_1463.pdf", "2")
        self.pdf_access("grader", "2", "8", "grading_homework_pdf", "words_249.pdf", "1")
    @unittest.skipUnless(os.environ.get('CI') is None, "cannot run in CI")
    def test_pdf_team_access(self):
        self.switch_settings("Limited Access Grader")
        self.pdf_access("instructor", "1", "7", "grading_homework_team_pdf", "words_881.pdf", "1")
        self.pdf_access("ta", "1", "6", "grading_homework_team_pdf", "words_881.pdf", "1")
        self.pdf_access("grader","1", "6", "grading_homework_team_pdf", "words_881.pdf", "1")
    @unittest.skipUnless(os.environ.get('CI') is None, "cannot run in CI")    
    def test_pdf_peer_access(self):
        self.switch_settings("Limited Access Grader")
        self.pdf_access("instructor", "3", "8", "grading_pdf_peer_homework", "words_1463.pdf", "1")
        self.pdf_access("ta", "3", "8", "grading_pdf_peer_homework", "words_1463.pdf", "1")
        self.pdf_access("grader","2", "6", "grading_pdf_peer_homework", "words_249.pdf", "1")
        self.pdf_access("student","2", "5", "grading_pdf_peer_homework", "words_249.pdf", "1")
    @unittest.skipUnless(os.environ.get('CI') is None, "cannot run in CI")    
    def test_pdf_peer_team_access(self):
        self.switch_settings("Limited Access Grader")
        self.pdf_access("instructor", "2", "8", "grading_pdf_peer_team_homework", "words_1463.pdf", "1")
        self.pdf_access("ta", "2", "6", "grading_pdf_peer_team_homework", "words_1463.pdf", "1")
        self.pdf_access("grader", "2", "6", "grading_pdf_peer_team_homework", "words_1463.pdf", "1")
        self.pdf_access("bauchg", "1", "5", "grading_pdf_peer_team_homework", "words_881.pdf", "1")
    def pdf_access(self, user_id, tr_number, td_number, gradeable_id, pdf_name, version):
        self.log_in(user_id=user_id)
        self.click_class('sample')
        self.driver.find_element_by_xpath('//a[contains(@href,"/sample/gradeable/'+gradeable_id+'/grading/status")]').click()
        self.wait_for_element((By.LINK_TEXT, "Grading Index"))
        self.driver.find_element_by_link_text("Grading Index").click()
        self.wait_for_element((By.ID, "details-table"))
        (self.driver.find_element_by_xpath("//tbody[@class='details-content panel-content-active']/tr["+tr_number+"]/td["+td_number+"]").find_elements_by_tag_name("a")[0]).click()
        self.wait_for_element((By.ID, "submission_browser"))
        self.driver.find_element_by_id('submissions').click()
        current_window = self.driver.window_handles[0]
        if not self.options.headless:
            self.driver.find_element_by_id('open_file_'+pdf_name).click()
            new_window = self.driver.window_handles[1]
            self.driver.switch_to.window(new_window)
            try:
                self.driver.find_element_by_xpath('//embed[contains(@type,"application/pdf")]')
            except NoSuchElementException:
                return False
            self.driver.close()
            self.driver.switch_to.window(current_window)
        self.driver.find_element_by_xpath('//a[contains(@file-url,"'+pdf_name+'")]').click()
        self.wait_for_element((By.ID, "pageContainer1"))
        self.driver.find_element_by_id('pageContainer1')
        self.driver.find_element_by_xpath('//a[contains(@onclick,"collapseFile()")]').click()
        self.driver.maximize_window()
        self.wait_for_element((By.ID, "logout"))
        self.log_out()
    def switch_settings(self, setting):
        self.log_in(user_id="instructor")
        self.click_class('sample')
        self.driver.find_element_by_xpath('//a[contains(@href,"/sample/gradeable/grading_homework_pdf/update")]').click()
        self.driver.find_element_by_id('page_3_nav').click()
        select_element = Select(self.driver.find_element_by_id("minimum_grading_group"))
        select_element.select_by_visible_text(setting)
        self.wait_for_element((By.ID, "logout"))
        self.log_out()

if __name__ == "__main__":
    import unittest
    unittest.main()
