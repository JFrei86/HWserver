"""Migration for the Submitty system."""

import os


def up(config):
    """
    Run up migration.

    :param config: Object holding configuration details about Submitty
    :type config: migrator.config.Config
    """
    os.system("pip3 install requests-html")
