# -*- mode: ruby -*-
# vi: set ft=ruby :

# Usage:
#   vagrant up
#       or
#   NO_SUBMISSIONS=1 vagrant up
#       or
#   EXTRA=rpi vagrant up
#
#
# If you want to install extra packages (such as rpi or matlab), you need to have the environment
# variable EXTRA set. The easiest way to do this is doing:
#
# EXTRA=rpi vagrant up
#   or
# EXTRA=rpi,matlab vagrant up
#
# If you don't want any submissions to be automatically generated for the courses created
# by vagrant, you'll want to specify NO_SUBMISSIONS flag.

# Don't buffer output.
$stdout.sync = true
$stderr.sync = true

extra_command = ''
if ENV.has_key?('NO_SUBMISSIONS')
    extra_command << '--no_submissions '
end
if ENV.has_key?('EXTRA')
    extra_command << ENV['EXTRA']
end

$script = <<SCRIPT
GIT_PATH=/usr/local/submitty/GIT_CHECKOUT/Submitty
DISTRO=$(lsb_release -si | tr '[:upper:]' '[:lower:]')
VERSION=$(lsb_release -sr | tr '[:upper:]' '[:lower:]')
bash ${GIT_PATH}/.setup/vagrant/setup_vagrant.sh #{extra_command} 2>&1 | tee ${GIT_PATH}/.vagrant/install_${DISTRO}_${VERSION}.log
SCRIPT

Vagrant.configure(2) do |config|
  # Specify the various machines that we might develop on. After defining a name, we
  # can specify if the vm is our "primary" one (if we don't specify a VM, it'll use
  # that one) as well as making sure all non-primary ones have "autostart: false" set
  # so that when we do "vagrant up", it doesn't spin up those machines.

  # This is what RPI uses as of Fall 2018, though currently migrating to 20.04
  config.vm.define 'ubuntu-18.04', autostart: false do |ubuntu|
    ubuntu.vm.box = 'bento/ubuntu-18.04'
    ubuntu.vm.network 'forwarded_port', guest: 1501, host: 1501   # site
    ubuntu.vm.network 'forwarded_port', guest: 8443, host: 8443   # Websockets
    ubuntu.vm.network 'forwarded_port', guest: 5432, host: 16432  # database
  end

  # Our primary development target, RPI uses it as of Fall 2021
  config.vm.define 'ubuntu-20.04', primary: true do |ubuntu|
    ubuntu.vm.box = 'bento/ubuntu-20.04'
    ubuntu.vm.network 'forwarded_port', guest: 1511, host: 1511   # site
    ubuntu.vm.network 'forwarded_port', guest: 8443, host: 8443   # Websockets
    ubuntu.vm.network 'forwarded_port', guest: 5432, host: 16442  # database
  end

  config.vm.provider 'virtualbox' do |vb|
    vb.memory = 2048
    vb.cpus = 2
    # When you put your computer (while running the VM) to sleep, then resume work some time later the VM will be out
    # of sync timewise with the host for however long the host was asleep. Of course, the VM by default will
    # detect this and if the drift is great enough, it'll resync things such that the time matches, otherwise
    # the VM will just slowly adjust the timing so they'll eventually match. However, this can cause confusion when
    # times are important for late day calculations and building so we set the maximum time the VM and host can drift
    # to be 10 seconds at most which should make things work generally ideally
    vb.customize ['guestproperty', 'set', :id, '/VirtualBox/GuestAdd/VBoxService/--timesync-set-threshold', 10000 ]

    # VirtualBox sometimes has isseus with getting the DNS to work inside of itself for whatever reason.
    # While it will sometimes randomly work, we can just set VirtualBox to use a DNS proxy from the host,
    # which seems to be far more reliable in having the DNS work, rather than leaving it to VirtualBox.
    # See https://serverfault.com/a/453260 for more info.
    # vb.customize ["modifyvm", :id, "--natdnsproxy1", "on"]
    vb.customize ["modifyvm", :id, "--natdnshostresolver1", "on"]
  end

  config.vm.provider "vmware_desktop" do |vm|
    vm.vmx["memsize"] = "2048"
    vm.vmx["numvcpus"] = "2"
  end

  config.vm.provision :shell, :inline => " sudo timedatectl set-timezone America/New_York", run: "once"

  # ideally we would use submitty_daemon or something as the owner/group, but since that user doesn't exist
  # till post-provision (and this is mounted before provisioning), we want the group to be 'vagrant'
  # which is guaranteed to exist and that during install_system.sh we add submitty_daemon/submitty_php/etc to the
  # vagrant group so that they can write to this shared folder, primarily just for the log files
  owner = 'root'
  group = 'vagrant'
  mount_options = %w(dmode=775 fmode=664)
  config.vm.synced_folder '.', '/usr/local/submitty/GIT_CHECKOUT/Submitty', create: true, owner: owner, group: group, mount_options: mount_options

  optional_repos = %w(AnalysisTools Lichen RainbowGrades Tutorial CrashCourseCPPSyntax LichenTestData)
  optional_repos.each {|repo|
    repo_path = File.expand_path("../" + repo)
    if File.directory?(repo_path)
      config.vm.synced_folder repo_path, "/usr/local/submitty/GIT_CHECKOUT/" + repo, owner: owner, group: group, mount_options: mount_options
    end
  }

  config.vm.provision 'shell', inline: $script

  if ARGV.include?('ssh')
    config.ssh.username = 'root'
    config.ssh.password = 'vagrant'
    config.ssh.insert_key = 'true'
  end
end
