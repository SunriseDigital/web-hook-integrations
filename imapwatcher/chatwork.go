package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/url"
	"text/template"
	"time"
)

type ChatWorkRoom struct {
	apiToken          string
	roomID            string
	lastMembers       []*ChatWorkUser
	nextMembersUpdate time.Time
	DryRun            bool
	Template          *template.Template
}

type ChatWorkUser struct {
	AccountID      int    `json:"account_id"`
	Name           string `json:"name"`
	AvatarImageURL string `json:"avatar_image_url"`
}

func NewChatWorkRoom(apiToken, roomID string) *ChatWorkRoom {
	return &ChatWorkRoom{
		apiToken:          apiToken,
		roomID:            roomID,
		nextMembersUpdate: time.Now(),
	}
}

func (cwr *ChatWorkRoom) Members() ([]*ChatWorkUser, error) {
	const accessInterval = time.Hour * 24
	if cwr.lastMembers != nil && !cwr.nextMembersUpdate.After(time.Now()) {
		return cwr.lastMembers, nil
	}
	cwr.nextMembersUpdate = time.Now().Add(accessInterval)

	req, err := http.NewRequest(
		"GET",
		fmt.Sprintf("https://api.chatwork.com/v1/rooms/%s/members", cwr.roomID),
		nil,
	)
	if err != nil {
		cwr.lastMembers = nil
		return nil, err
	}
	req.Header.Add("X-ChatWorkToken", cwr.apiToken)

	client := &http.Client{}
	resp, err := client.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	var members []*ChatWorkUser
	err = json.NewDecoder(resp.Body).Decode(&members)
	if err != nil {
		return nil, err
	}

	cwr.lastMembers = members
	return members, nil
}

func (cwr *ChatWorkRoom) Say(message string) error {
	if cwr.DryRun {
		fmt.Println(message)
		return nil
	}

	req, err := http.NewRequest(
		"POST",
		fmt.Sprintf("https://api.chatwork.com/v1/rooms/%s/messages", cwr.roomID),
		bytes.NewBufferString((&url.Values{"body": []string{message}}).Encode()),
	)
	if err != nil {
		return err
	}

	client := &http.Client{}
	req.Header.Add("X-ChatWorkToken", cwr.apiToken)
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	resp, err := client.Do(req)
	if err != nil {
		return err
	}

	if err = resp.Body.Close(); err != nil {
		return err
	}
	return nil
}
