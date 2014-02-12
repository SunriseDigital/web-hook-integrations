package main

import (
	"bytes"
	"code.google.com/p/go-imap/go1/imap"
	"flag"
	"fmt"
	"github.com/oov/mail"
	"github.com/oov/mail/jsonmail"
	"log"
	"net/textproto"
	"text/template"
)

var (
	messageTemplate  = flag.String("tpl", "message.tmpl", "message template")
	chatworkAPIToken = flag.String("api", "", "chatwork api token")
	chatworkRoomID   = flag.String("room", "", "chatwork room id")
	chatworkDryRun   = flag.Bool("d", false, "print to stdout instead of chatwork api")
	imapHost         = flag.String("imap", "", "imap server address")
	imapUsername     = flag.String("user", "", "imap account username")
	imapPassword     = flag.String("pass", "", "imap account password")
	imapMailbox      = flag.String("mbox", "INBOX", "mailbox name")
	imapExpunge      = flag.Bool("expunge", true, "execute expunge")
	imapVerbose      = flag.Bool("v", false, "verbose imap log")
)

func main() {
	flag.Parse()
	if *chatworkAPIToken == "" || *chatworkRoomID == "" ||
		*imapHost == "" || *imapUsername == "" || *imapPassword == "" {
		fmt.Println("missing parameter.")
		flag.Usage()
		return
	}

	tpl, err := template.ParseFiles(*messageTemplate)
	if err != nil {
		log.Fatalln("could not parse message template:", err)
	}

	imp := NewIMAPWatcher(*imapHost, *imapUsername, *imapPassword, *imapMailbox)
	imp.Expunge = *imapExpunge
	if *imapVerbose {
		imap.DefaultLogMask = imap.LogAll
	}

	cwr := NewChatWorkRoom(*chatworkAPIToken, *chatworkRoomID)
	cwr.DryRun = *chatworkDryRun

	mailCh := make(chan []byte)
	go imp.Watch(mailCh)
	for msgBody := range mailCh {
		if err := process(msgBody, cwr, tpl); err != nil {
			log.Println(err)
			continue
		}
	}
}

func process(msgBody []byte, cwr *ChatWorkRoom, tpl *template.Template) error {
	msg, err := mail.ReadMessage(bytes.NewReader(msgBody))
	if err != nil {
		return err
	}

	jsmRoot, err := jsonmail.Parse(msg)
	if err != nil {
		return err
	}

	var tplData struct {
		Members []*ChatWorkUser
		Header  textproto.MIMEHeader
		Text    string
	}
	tplData.Header = jsmRoot.Header

	if tplData.Text, _, err = jsmRoot.FindTextBody(); err != nil {
		return err
	}

	if tplData.Members, err = cwr.Members(); err != nil {
		return err
	}

	buf := bytes.NewBufferString("")
	if err = tpl.Execute(buf, tplData); err != nil {
		return err
	}
	return cwr.Say(buf.String())
}
