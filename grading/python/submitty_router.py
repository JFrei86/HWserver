import socket
import sys
import csv
import traceback
import queue
import errno
from time import sleep
import os
import datetime
import random
from datetime import timedelta  

class submitty_router():
  '''
  A constructor for the standard router, set seed to a positive integer to
    make a number of router functions deterministic run-on-run.
  '''
  def __init__(self, seed=None, log_file='router_log.txt'):
    if seed != None:
      random.seed( INSTRUCTOR_SEED )
    # Variable to keep track of how many messages we have intercepted so far.
    self.messages_intercepted = 0
    # Initialized when run is called.
    self.execution_start_time = None
    self.log_file = log_file
    self.sequence_diagram_file = 'sequence_diagram.txt'
    self.switchboard = {}
    self.ports = list()
    self.p_queue = queue.PriorityQueue()
    self.running = False

  ##################################################################################################################
  # INSTRUCTOR FUNCTIONS
  ##################################################################################################################
  '''
  This function may be used to manipulate when a message will be processed (forwarded to its recipient) and
  what its contents are. It must return a tuple of the form (<time to be processed>, data), where data is a 
  dictionary containing 'sender', 'recipient', 'port', and 'message'. It is not recommended that port, sender,
  or recipient be manipulated.
  '''
  def manipulate_recieved_message(self, sender, recipient, port, message, message_number):
    now = datetime.datetime.now()
    # The total time the program has been running as of right now.
    elapsed_time = now - self.execution_start_time
    # Use this time to process the student message instantly
    process_time = now
    # Leave blank to avoid outputting a message to the student on their sequence diagram
    message_to_student = None
    drop_me = False

    data = {
      'sender' : sender,
      'recipient' : recipient,
      'port' : port,
      'message' : message,
      'message_to_student' : message_to_student,
      'drop_message' : drop_me
    }
    return (process_time, data)


  ##################################################################################################################
  # LOGGING FUNCTIONS
  ##################################################################################################################
  def convert_queue_obj_to_string(self, obj):
    str = '\tSENDER: {0}\n\tRECIPIENT: {1}\n\tPORT: {2}\n\tCONTENT: {3}'.format(obj['sender'], obj['recipient'], obj['port'], obj['message'])
    return str

  def log(self, line):
    if os.path.exists(self.log_file):
      append_write = 'a' # append if already exists
    else:
        append_write = 'w' # make a new file if not
    with open(self.log_file, mode=append_write) as out_file:
      out_file.write(line + '\n')
      out_file.flush()
    print(line)
    sys.stdout.flush()

  def write_sequence_file(self, obj, status, message_type):
    append_write = 'a' if os.path.exists(self.sequence_diagram_file) else 'w'

    #select the proper arrow type for the message
    if status == 'success':
      arrow = '->>' if message_type == 'tcp' else '-->>'
    else:
      arrow = '-x' if message_type == 'tcp' else '--x'

    sender = obj['sender'].replace('_Actual', '')
    recipient = obj['recipient'].replace('_Actual', '')

    with open(self.sequence_diagram_file, append_write) as outfile:
      outfile.write('{0}{1}{2}: {3}\n'.format(sender, arrow, recipient, str(obj['message'])))
      if 'message_to_student' in obj and obj['message_to_student'] != None and obj['message_to_student'].strip() != '':
        outfile.write('Note over {0},{1}: {2}\n'.format(sender, recipient, obj['message_to_student']))
      # writer = csv.writer(outfile)
      # #sender, recipient, message, port, status, message_type, timestamp
      # writer.writerow([obj['sender'].replace('_Actual', ''), obj['recipient'].replace('_Actual', ''), str(obj['message']), obj['port'], status, message_type, str(datetime.datetime.now())])



  ##################################################################################################################
  # SWITCHBOARD FUNCTION
  ##################################################################################################################

  '''
  knownhosts_tcp.txt and knownhosts_udp.txt are of the form
  sender recipient port_number
  such that sender sends all communications to recipient via port_number. 
  '''
  def build_switchboard(self):
    try:
      #Read the known_hosts.csv see the top of the file for the specification
      for connection_type in ["tcp", "udp"]:
        filename = 'knownhosts_{0}.txt'.format(connection_type)
        with open(filename, 'r') as infile:
          content = infile.readlines()    
          
        for line in content:
          sender, recipient, port = line.split()
          #Strip away trailing or leading whitespace
          sender = '{0}_Actual'.format(sender.strip())
          recipient = '{0}_Actual'.format(recipient.strip())
          port = port.strip()

          if not port in self.ports:
            self.ports.append(port)
          else:
            raise SystemExit("ERROR: port {0} was encountered twice. Please keep all ports independant.".format(port))

          self.switchboard[port] = {}
          self.switchboard[port]['connection_type'] = connection_type
          self.switchboard[port]['sender'] = sender
          self.switchboard[port]['recipient'] = recipient
          self.switchboard[port]['connected'] = False
          self.switchboard[port]['connection'] = None
    except IOError as e:
      self.log("ERROR: Could not read {0}.".format(filename))
      self.log(traceback.format_exc())
    except ValueError as e:
      self.log("ERROR: {0} was improperly formatted. Please include lines of the form (SENDER, RECIPIENT, PORT)".format(filename))
    except Exception as e:
      self.log('Encountered an error while reading and parsing {0}'.format(filename))
      self.log(traceback.format_exc())


  ##################################################################################################################
  # OUTGOING CONNECTION/QUEUE FUNCTIONS
  ##################################################################################################################


  def connect_outgoing_socket(self, port):
    if self.switchboard[port]['connected']:
      return

    connection_type = self.switchboard[port]["connection_type"]

    recipient = self.switchboard[port]['recipient']
    server_address = (recipient, int(port))

    if connection_type == 'tcp':
      sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
      sock.connect(server_address)
    else:
      sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)

    #We catch errors one level up.
    name = recipient.replace('_Actual', '')
    self.log("Established outgoing connection to {0} on port {1}".format(name, port))
    self.switchboard[port]['connected'] = True
    self.switchboard[port]['outgoing_socket'] = sock

  def send_outgoing_message(self, data):
    status = 'unset'
    message_type = 'unset'
    try:
      drop_message = data.get('drop_message', False)
      port = data['port']
      message = data['message']
      sock = self.switchboard[port]['outgoing_socket']
      recipient = data['recipient']
    except:
      status = 'router_error'
      self.log("An error occurred internal to the router. Please report the following error to a Submitty Administrator")
      self.log(traceback.format_exc())
      self.write_sequence_file(data, status, message_type)
      return
    try:
      message_type = self.switchboard[port]['connection_type']
      if drop_message:
        success = "dropped"
        self.log("Choosing not to deliver message {!r} to {}".format(message, recipient.replace('_Actual', '')))
      elif message_type == 'tcp':
        sock.sendall(message)
        self.log('Sent message {!r} to {}'.format(message,recipient.replace('_Actual', '')))
        status = 'success'
      else:
        destination_address = (recipient, int(port))
        sock.sendto(message,destination_address)
        self.log('Sent message {!r} to {}'.format(message,recipient.replace('_Actual', '')))
        status = 'success'
    except:
      self.log('Could not deliver message {!r} to {}'.format(message,recipient))
      self.switchboard[port]['connected'] = False
      self.switchboard[port]['connection'].close()
      self.switchboard[port]['connection'] = None
      status = 'failure'
    self.write_sequence_file(data, status, message_type)

  def process_queue(self):
    # The still_going variable/loop protects us against multiple 
    #  enqueued items with the same send time.
    still_going = True
    while still_going:
      try:
        now = datetime.datetime.now()
        #priority queue has no peek function due to threading issues.
        #  as a result, pull it off, check it, then put it back on.
        value = self.p_queue.get_nowait()
        if value[0] <= now:
          self.send_outgoing_message(value[1])
        else:
          self.p_queue.put(value)
          still_going = False
      except queue.Empty:
        still_going = False


  ##################################################################################################################
  # INCOMING CONNECTION FUNCTIONS
  ##################################################################################################################

  def connect_incoming_sockets(self):
    for port in self.ports:
      self.open_incoming_socket(port)

  def open_incoming_socket(self, port):
    # Create a TCP/IP socket

    connection_type = self.switchboard[port]['connection_type']
    sender = self.switchboard[port]['sender']

    if connection_type == "tcp":
      sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    elif connection_type == "udp":
      sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    else:
      self.log("ERROR: bad connection type {0}. Please contact an administrator".format(connection_type))
      sys.exit(1)

    #Bind the socket to the port
    server_address = ('', int(port))
    sock.bind(server_address)
    sock.setblocking(False)

    self.log('Bound socket port {0}'.format(port))

    if connection_type == 'tcp':
      #listen for at most 1 incoming connections at a time.
      sock.listen(1)

    self.switchboard[port]['incoming_socket'] = sock

    if connection_type == 'udp':
      self.switchboard[port]['connection'] = sock

  def listen_to_sockets(self):
    for port in self.ports:
      try:
        connection_type = self.switchboard[port]["connection_type"]
        if connection_type == 'tcp':
          if self.switchboard[port]["connection"] == None:
            sock = self.switchboard[port]['incoming_socket']
            # Accept the message
            connection, client_address = sock.accept()
            # Set the connection to non_blocking
            connection.setblocking(False)
            self.switchboard[port]['connection'] = connection
            name = self.switchboard[port]['sender'].replace('_Actual', '')
            self.log('established connection with {0} on port {1}'.format(name, port))
          else:
            connection = self.switchboard[port]['connection']
        elif connection_type == 'udp':
            connection = self.switchboard[port]["connection"]
        else:
          self.log('Invalid connection type {0}. Please contact an administrator with this error.'.format(connection_type))
          sys.exit(1)

        #TODO: May have to the max recvfrom size.
        #The recvfrom call will raise a OSError if there is nothing to receive. 
        message, snd = connection.recvfrom(4096)
        sender = self.switchboard[port]['sender'].replace("_Actual", "")

        if message.decode('utf-8') == '' and connection_type == 'tcp':
          self.log('Host {0} disconnected on port {1}.'.format(sender,port))
          self.switchboard[port]['connected'] = False
          self.switchboard[port]['connection'].close()
          self.switchboard[port]['connection'] = None
          continue

        self.log('Received message {!r} from {} on port {}'.format(message,sender,port))

        #if we did not error:
        self.connect_outgoing_socket(port)
        recipient = self.switchboard[port]['recipient']
        
        self.messages_intercepted += 1
        
        tup = self.manipulate_recieved_message(sender, recipient, port, message, self.messages_intercepted)
        assert isinstance(tup[0], datetime.datetime), "{0} is not a datetime".format(tup[0]) 
        assert isinstance(tup[1], dict), "{0} is not a dictionary".format(tup[1])
        self.p_queue.put(tup)
      except socket.timeout as e:
        #This is likely an acceptable error caused by non-blocking sockets having nothing to read.
        err = e.args[0]
        if err == 'timed out':
          self.log('no data')
        else:
          self.log('real error!')
          self.log(traceback.format_exc())
      except BlockingIOError as e:
        pass
      except ConnectionRefusedError as e:
        #this means that connect_outgoing_tcp didn't work.
        self.log('Connection on outgoing channel not established. Message dropped.')
        self.log(traceback.format_exc())
        self.switchboard[port]['connected'] = False
      except socket.gaierror as e:
        self.log("Unable to connect to unknown/not set up entity.")
        self.log(traceback.format_exc())
      except Exception as e:
        self.log("ERROR: error listening to socket {0}".format(port))
        self.log(traceback.format_exc())


  ##################################################################################################################
  # CONTROL FUNCTIONS
  ##################################################################################################################


  #Do everything that should happen before multiprocessing kicks in.
  def init(self):
    self.log('Booting up the router...')
    self.build_switchboard()
    #Only supporting tcp at the moment.
    self.log('Connecting incoming sockets...')
    self.connect_incoming_sockets()

  def run(self):
    self.running = True
    #sleep(1)
    self.execution_start_time = datetime.datetime.now()
    self.log('Listening for incoming connections...')
    while self.running:
      self.listen_to_sockets()
      self.process_queue()


if __name__ == '__main__':
  router = submitty_router()
  router.init()
  router.run()

