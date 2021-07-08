from .base_testcase import BaseTestCase
from selenium.webdriver.common.by import By


class TestAnonMode(BaseTestCase):
    def __init__(self, testname):
        super().__init__(testname, log_in=False)

    def test_peer_anon(self):
        self.log_in(user_id="student", user_name="Joe")
        self.click_class('sample')
        self.driver.find_element_by_xpath('//a[contains(@href,"/sample/gradeable/grading_pdf_peer_homework/grading/status")]').click()
        self.wait_for_element((By.LINK_TEXT, "Grading Index"))
        self.driver.find_element_by_link_text("Grading Index").click()
        self.wait_for_element((By.ID, "details-table"))
        page_source = self.driver.page_source
        page_source_processed = page_source[0:page_source.index("page-info")]
        self.assertFalse("towneo" in page_source_processed)
        (self.driver.find_element_by_xpath("//tbody[@class='details-content panel-content-active']/tr[6]/td[5]").find_elements_by_tag_name("a")[0]).click()
        self.wait_for_element((By.ID, "submission_browser"))
        page_source = self.driver.page_source
        page_source_processed = page_source[0:page_source.index("page-info")]
        self.assertFalse("towneo" in page_source_processed)
        
    def test_peer_team_anon(self):
        self.log_in(user_id="student", user_name="Joe")
        self.click_class('sample')
        self.driver.find_element_by_xpath('//a[contains(@href,"/sample/gradeable/grading_pdf_peer_team_homework/grading/status")]').click()
        self.wait_for_element((By.LINK_TEXT, "Grading Index"))
        self.driver.find_element_by_link_text("Grading Index").click()
        self.wait_for_element((By.ID, "details-table"))
        page_source = self.driver.page_source
        page_source_processed = page_source[0:page_source.index("page-info")]
        self.assertFalse("thompc" in page_source_processed)
        self.assertFalse("turnej" in page_source_processed)
        self.assertFalse("uptonr" in page_source_processed)
        (self.driver.find_element_by_xpath("//tbody[@class='details-content panel-content-active']/tr[6]/td[5]").find_elements_by_tag_name("a")[0]).click()
        self.wait_for_element((By.ID, "submission_browser"))
        page_source = self.driver.page_source
        page_source_processed = page_source[0:page_source.index("page-info")]
        self.assertFalse("thompc" in page_source_processed)
        self.assertFalse("turnej" in page_source_processed)
        self.assertFalse("uptonr" in page_source_processed)
if __name__ == "__main__":
    import unittest
    unittest.main()
