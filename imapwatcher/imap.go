package main

import (
	"code.google.com/p/go-imap/go1/imap"
	"fmt"
	"io"
	"log"
	"os"
	"time"
)

type imapWatcher struct {
	host     string
	username string
	password string
	mailbox  string
	Expunge  bool
	Logger   *log.Logger
}

func NewIMAPWatcher(host, username, password, mailbox string) *imapWatcher {
	return &imapWatcher{
		host:     host,
		username: username,
		password: password,
		mailbox:  mailbox,
		Logger:   log.New(os.Stderr, "", log.LstdFlags),
	}
}

func (imp *imapWatcher) Watch(mail chan<- []byte) {
	highFreq := 0
	for {
		r := time.Now().Add(30 * time.Second)
		if err := imp.process(mail); err != nil {
			imp.Logger.Println(err)
		}
		if r.After(time.Now()) {
			highFreq++
			if highFreq == 3 {
				imp.Logger.Fatalln("too many retries with high frequency")
			}
		} else {
			highFreq = 0
		}
	}
}

func (imp *imapWatcher) process(mail chan<- []byte) error {
	c, err := imap.Dial(imp.host)
	if err != nil {
		return err
	}
	defer c.Logout(-1)

	if c.State() == imap.Login {
		if _, err = imap.Wait(c.Login(imp.username, imp.password)); err != nil {
			return err
		}
	} else {
		return fmt.Errorf("Unknown state: %s", c.State())
	}

	for {

		if _, err = c.Select(imp.mailbox, false); err != nil {
			return fmt.Errorf("Could not select %s: %v", imp.mailbox, err)
		}

		searchUndeleted, err := imap.Wait(c.Search("UNDELETED"))
		if err != nil {
			return fmt.Errorf("Could not search UNDELETED: %v", err)
		}
		if len(searchUndeleted.Data) != 1 {
			return fmt.Errorf("Invalid search result: %v", searchUndeleted.Data)
		}

		undeleted := searchUndeleted.Data[0].SearchResults()
		if len(undeleted) == 0 {
			if _, err = c.Idle(); err != nil {
				return fmt.Errorf("Could not start idle: %v", err)
			}
			for {
				if err = c.Recv(-1); err != nil {
					if err == io.EOF {
						return nil
					}
					return err
				}
				if c.Data[len(c.Data)-1].Label == "EXISTS" {
					break
				}
			}

			if _, err = c.IdleTerm(); err != nil {
				if err == io.EOF {
					return nil
				}
				return fmt.Errorf("Could not end idle: %v", err)
			}

			if _, err = c.Select(imp.mailbox, false); err != nil {
				return fmt.Errorf("Could not select %s: %v", imp.mailbox, err)
			}

			searchUndeleted, err = imap.Wait(c.Search("UNDELETED"))
			if err != nil {
				return fmt.Errorf("Could not search UNDELETED: %v", err)
			}
			if len(searchUndeleted.Data) != 1 {
				return fmt.Errorf("Invalid search result: %v", searchUndeleted.Data)
			}

			undeleted = searchUndeleted.Data[0].SearchResults()
		}

		set, _ := imap.NewSeqSet("")
		set.AddNum(undeleted...)
		cmd, err := c.Fetch(set, "BODY[]")
		if err != nil {
			return err
		}

		processed, _ := imap.NewSeqSet("")
		for cmd.InProgress() {
			if err = c.Recv(-1); err != nil {
				return err
			}
			// Process command data
			for _, rsp := range cmd.Data {
				msgBody := imap.AsBytes(rsp.MessageInfo().Attrs["BODY[]"])
				if len(msgBody) == 0 {
					// FLAG?
					continue
				}
				mail <- msgBody
				processed.AddNum(rsp.MessageInfo().Seq)
			}
			cmd.Data = nil
		}

		if _, err = imap.Wait(c.Store(processed, "+FLAGS", `(\Deleted)`)); err != nil {
			return err
		}

		if imp.Expunge {
			if _, err = imap.Wait(c.Expunge(nil)); err != nil {
				return err
			}
		}
	}
	return nil
}
