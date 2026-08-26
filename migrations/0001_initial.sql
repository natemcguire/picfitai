
CREATE TABLE IF NOT EXISTS users (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE,
    avatar_url TEXT DEFAULT '✨',
    credits_remaining INTEGER DEFAULT 100,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS generations (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    outfit_name TEXT NOT NULL,
    outfit_url TEXT,
    person_url TEXT,
    result_url TEXT,
    status TEXT DEFAULT 'completed',
    processing_time_ms INTEGER DEFAULT 840,
    is_public BOOLEAN DEFAULT TRUE,
    share_token TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS credit_transactions (
    id TEXT PRIMARY KEY,
    user_id TEXT NOT NULL,
    amount INTEGER NOT NULL,
    type TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Seed Default Maker User
INSERT OR IGNORE INTO users (id, name, email, credits_remaining) 
VALUES ('usr_nate', 'Nate McGuire', 'nate@nates-software.com', 100);

-- Seed Sample Generations
INSERT OR IGNORE INTO generations (id, user_id, outfit_name, outfit_url, result_url, status, processing_time_ms, is_public, share_token)
VALUES 
('gen-01', 'usr_nate', 'The Emmy Red Carpet Gown', '/images/outfits/theemmys/emmy-1.jpg', '/images/outfits/Wedding.png', 'completed', 720, 1, 'tok_emmy_981'),
('gen-02', 'usr_nate', 'Midnight Blue Wool Tuxedo', '/images/outfits/Blue Suit.png', '/images/outfits/Tux.png', 'completed', 650, 1, 'tok_tux_442'),
('gen-03', 'usr_nate', 'NASA Apollo Astronaut Suit', '/images/outfits/Astronaut.png', '/images/outfits/NASA.png', 'completed', 810, 1, 'tok_nasa_110');
