import pandas as pd
import numpy as np
from sklearn.model_selection import train_test_split
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.metrics import classification_report, confusion_matrix
import matplotlib.pyplot as plt
import seaborn as sns
import pickle
import json
import os
import hashlib
from datetime import datetime
import mysql.connector
from mysql.connector import Error

# Get script directory for relative paths
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
PROJECT_DIR = os.path.dirname(SCRIPT_DIR)

def connect_to_database():
    try:
        connection = mysql.connector.connect(
            host='localhost',
            database='sentiment_analysis',
            user='root',
            password=''
        )
        return connection
    except Error as e:
        print(f"Error connecting to database: {e}")
        return None

def load_data(dataset_id=None, csv_path=None):
    if csv_path:
        print(f"Loading data from CSV: {csv_path}")
        df = pd.read_csv(csv_path)

        # Normalize column names
        df.columns = [c.lower() for c in df.columns]

        # Find text column
        text_col = next((c for c in df.columns if c in ['text', 'teks', 'content', 'review']), None)
        # Find sentiment column
        label_col = next((c for c in df.columns if c in ['sentiment', 'label', 'class']), None)

        if not text_col:
             raise Exception("CSV must contain a text column (text, teks, content, review)")

        # Rename for consistency
        df = df.rename(columns={text_col: 'processed_text'})
        if label_col:
            df = df.rename(columns={label_col: 'sentiment'})
        else:
            df['sentiment'] = None

        return df

    connection = connect_to_database()
    if connection is None:
        raise Exception("Could not connect to database")

    try:
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT text, processed_text, sentiment
            FROM dataset_items
            WHERE dataset_id = %s
            AND processed_text IS NOT NULL
            AND processed_text != ''
            AND sentiment IN ('positive', 'negative', 'neutral')
        """, (dataset_id,))

        rows = cursor.fetchall()
        if not rows:
            raise Exception(f"No data found for dataset_id {dataset_id}")

        df = pd.DataFrame(rows)
        print(f"Loaded {len(df)} rows of data")
        print("Sentiment distribution:")
        print(df['sentiment'].value_counts())

        return df
    except Error as e:
        print(f"Error querying database: {e}")
        raise
    finally:
        if connection.is_connected():
            cursor.close()
            connection.close()

def preprocess_text(text):
    if pd.isna(text) or text == '':
        return ''
    # Gunakan processed_text yang sudah ada
    return str(text).lower().strip()

def train_model(dataset_id=None, csv_path=None):
    print("Loading dataset...")
    df = load_data(dataset_id, csv_path)

    if df.empty:
        print("No data found for dataset_id:", dataset_id)
        return

    # Preprocess text
    print("Preprocessing text...")
    df['processed_text'] = df['processed_text'].fillna('')

    # Hapus data dengan teks kosong
    df = df[df['processed_text'].str.strip() != '']
    print(f"Data after removing empty texts: {len(df)} rows")

    if len(df) == 0:
        print("No valid data after preprocessing")
        return

    # Validate sentiment column
    df = df[df['sentiment'].notna()]
    df = df[df['sentiment'].isin(['positive', 'negative', 'neutral'])]

    if len(df) < 10:
        print(f"Not enough labeled data: {len(df)} rows. Need at least 10.")
        return

    # Define features (X) and target (y)
    X = df['processed_text']
    y = df['sentiment']

    print(f"Sentiment distribution:\n{y.value_counts()}")

    # Split data
    print("Splitting dataset...")

    # Check if we have enough samples for stratified split
    min_class_count = y.value_counts().min()
    if min_class_count < 2:
        print("Warning: Some classes have less than 2 samples. Using non-stratified split.")
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42
        )
    else:
        X_train, X_test, y_train, y_test = train_test_split(
            X, y, test_size=0.2, random_state=42, stratify=y
        )

    # Create and train vectorizer
    print("Training vectorizer...")
    # Adjust min_df based on dataset size
    min_df_val = 2 if len(X_train) > 100 else 1
    vectorizer = CountVectorizer(min_df=min_df_val, max_df=0.95)
    X_train_vec = vectorizer.fit_transform(X_train)
    X_test_vec = vectorizer.transform(X_test)

    if len(vectorizer.vocabulary_) == 0:
        print("Error: Vocabulary is empty. Cannot train model.")
        return

    print(f"Vocabulary size: {len(vectorizer.vocabulary_)}")
    print(f"Training data shape: {X_train_vec.shape}")
    print(f"Testing data shape: {X_test_vec.shape}")

    # Save vectorizer ke root/models/ (konsisten dengan SentimentModel.php)
    print("Saving vectorizer...")
    models_dir = os.path.join(PROJECT_DIR, 'models')
    os.makedirs(models_dir, exist_ok=True)
    with open(os.path.join(models_dir, 'vectorizer.pkl'), 'wb') as f:
        pickle.dump(vectorizer, f)

    # Train Naive Bayes (MultinomialNB lebih tepat untuk word count data)
    print("Training Naive Bayes model...")
    classifier = MultinomialNB()
    classifier.fit(X_train_vec, y_train)
    # Save model
    print("Saving model...")
    with open(os.path.join(models_dir, 'naive_bayes.pkl'), 'wb') as f:
        pickle.dump(classifier, f)

    # Evaluate model
    print("\nEvaluating model...")
    y_pred = classifier.predict(X_test_vec)

    # Generate and print detailed metrics
    print("\nDetailed Metrics:")
    print("Unique values in y_test:", np.unique(y_test))
    print("Unique values in y_pred:", np.unique(y_pred))

    # Generate classification report
    report = classification_report(y_test, y_pred)
    print("\nClassification Report:")
    print(report)

    # Create confusion matrix
    cm = confusion_matrix(y_test, y_pred)
    print("\nConfusion Matrix:")
    print(cm)

    # Generate a fallback ID if dataset_id is None
    effective_id = dataset_id
    if effective_id is None:
        # Use hash of CSV path or timestamp as fallback
        effective_id = hashlib.md5(str(csv_path or datetime.now()).encode()).hexdigest()[:8]

    # Save test data and classification report
    test_data = {
        'X_test': X_test.tolist(),
        'y_test': y_test.tolist(),
        'y_pred': y_pred.tolist(),
        'classification_report': report
    }

    testing_dir = os.path.join(PROJECT_DIR, 'pages', 'data', 'testing')
    os.makedirs(testing_dir, exist_ok=True)
    json_path = os.path.join(testing_dir, f'test_data_{effective_id}.json')
    with open(json_path, 'w') as f:
        json.dump(test_data, f)
    print(f"Saved test data to: {json_path}")

    # Plot confusion matrix with better visibility
    plt.figure(figsize=(10,8))
    sns.heatmap(cm, annot=True, fmt='d', cmap='Blues',
                xticklabels=sorted(y.unique()),
                yticklabels=sorted(y.unique()))
    plt.xlabel('Prediksi')
    plt.ylabel('Aktual')
    plt.title('Confusion Matrix Naive Bayes')

    # Save plot with better quality
    print("Saving confusion matrix plot...")
    plt.savefig(os.path.join(models_dir, 'confusion_matrix.png'), bbox_inches='tight', dpi=300)
    plt.close()

    print("\nTraining completed successfully!")

if __name__ == "__main__":
    import argparse
    import sys

    parser = argparse.ArgumentParser(description='Train Sentiment Model')
    parser.add_argument('--dataset_id', type=str, help='ID of the dataset in database')
    parser.add_argument('--csv', type=str, help='Path to CSV file')
    args = parser.parse_args()

    if not args.dataset_id and not args.csv:
        print("Error: Either --dataset_id or --csv must be provided")
        sys.exit(1)

    try:
        if args.csv:
            train_model(dataset_id=args.dataset_id, csv_path=args.csv)
        else:
            train_model(dataset_id=args.dataset_id)
    except Exception as e:
        print(f"Error during training: {e}")
        import traceback
        traceback.print_exc()
        sys.exit(1)